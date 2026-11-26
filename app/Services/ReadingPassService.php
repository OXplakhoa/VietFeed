<?php

namespace App\Services;

use App\Models\Article;
use App\Models\User;
use App\ReadingPass\ReadingPassHistory;
use App\ReadingPass\ReadingPassQuota;
use App\ReadingPass\UnlockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use RuntimeException;
use Throwable;

/**
 * Reading Pass (Gate 9.5 slice 3b): MongoDB ledger (durable), Redis Lua window (atomic quota), Cassandra history.
 *
 * Tiers and copy are unchanged: admin unlimited and unrecorded; Pro unlimited and recorded; verified 15 per rolling
 * 5 hours; unverified account 8 and guest session 5 per Vietnam day. Re-opening an unlocked article never consumes.
 * With Redis unavailable the decision falls back to a MongoDB count of the same window (see docs/database/nosql-local.md).
 */
class ReadingPassService
{
    /** Rebuild lock wait used by decisions (ReadingPassQuota::lock default). */
    private const LOCK_WAIT_MS = 1000;

    /** Assumed Redis command budget when the `nosql` connection sets no timeout, plus a margin. */
    private const REDIS_BUDGET_MS = 2000;

    private const SETTLE_MARGIN_MS = 500;

    public function __construct(
        private UnlockLedger $ledger,
        private ReadingPassQuota $quota,
        private ReadingPassHistory $history,
    ) {}

    /**
     * Allowance info for the current user/session context.
     *
     * @return array{tier: string, limit: int, used: int, remaining: int, window: string, reset_label: string, next_unlock_label: ?string}
     */
    public function allowance(?User $user): array
    {
        $now = CarbonImmutable::now('Asia/Ho_Chi_Minh');
        $plan = $this->plan($user, $now);
        if ($plan['limit'] === -1) {
            return $this->unlimited($plan['tier']);
        }

        return $this->allowanceFrom($plan, $this->usage($plan, $now), $now);
    }

    /**
     * Check whether the given article can be accessed, and if so, record the unlock.
     *
     * @return array{canAccess: bool, allowance: array}
     */
    public function accessOrLock(Article $article, ?User $user): array
    {
        return $this->access($article, $user, CarbonImmutable::now('Asia/Ho_Chi_Minh'));
    }

    public function seedSyntheticUnlock(Article $article, User $user, string $eventId, CarbonImmutable $at): void
    {
        $result = $this->access($article, $user, $at->setTimezone('Asia/Ho_Chi_Minh'), $eventId);
        $doc = $this->ledger->collection()->findOne(['_id' => $eventId, 'state' => UnlockLedger::GRANTED, 'synthetic' => true]);
        if (! $result['canAccess'] || $doc === null) {
            throw new RuntimeException('Synthetic Reading Pass unlock did not settle. Retry the same seed.');
        }
        if (! $this->history->publish($doc)) {
            throw new RuntimeException('Synthetic Reading Pass unlock could not be published. Retry the same seed.');
        }
    }

    private function access(Article $article, ?User $user, CarbonImmutable $now, ?string $syntheticId = null): array
    {
        $plan = $this->plan($user, $now);

        // Admin support/debug access should not consume or inflate Reading Pass metrics.
        if ($plan['tier'] === 'admin') {
            return ['canAccess' => true, 'allowance' => $this->unlimited('admin')];
        }

        $fields = [
            'subject' => $plan['subject'],
            'user_id' => $user ? (string) $user->getKey() : null,
            'session_hash' => $user ? null : UnlockLedger::sessionHash($this->sessionId()),
            'article_id' => (string) $article->getKey(),
            'origin' => $syntheticId === null ? 'unlock' : 'synthetic',
        ];
        if ($syntheticId !== null) {
            $fields += ['_id' => $syntheticId, 'synthetic' => true, 'synthetic_seed' => $user->synthetic_seed];
        }
        $deadline = hrtime(true) + $this->settleMs() * 1_000_000;
        while (true) {
            $found = $this->ledger->lookup($plan['subject'], $fields['article_id']);
            $doc = $found['doc'];
            if ($doc !== null && $syntheticId !== null && $doc['_id'] !== $syntheticId) {
                throw new RuntimeException('Synthetic unlock conflicts with an existing record.');
            }
            if ($doc !== null && $doc['state'] === UnlockLedger::GRANTED) {
                // Already unlocked: access without consuming.
                if ($found['fallback']) {
                    $this->rebuildQuietly($plan['subject'], $now, force: true);
                }

                return ['canAccess' => true, 'allowance' => $this->allowanceFrom($plan, $plan['limit'] === -1 ? null : $this->usage($plan, $now), $now)];
            }
            if ($doc === null) {
                $id = $this->ledger->reserve($fields, $now);
                if ($id !== null) {
                    return $this->decide($plan, $fields, $id, $found['fallback'], $now);
                }
            } elseif ($this->reservationIsStale($doc, $now, $syntheticId !== null)) {
                $this->ledger->release($doc['_id']); // Abandoned reservation of a crashed request.

                continue;
            }
            // A concurrent request for the same article holds the reservation: re-check until it is granted
            // (→ already unlocked) or released (→ decide normally), within the longest time it can take.
            if (hrtime(true) >= $deadline) {
                return $this->unsettled($plan, $doc, $now);
            }
            usleep(25_000);
        }
    }

    private function reservationIsStale(array $doc, CarbonImmutable $now, bool $synthetic): bool
    {
        // Synthetic unlocks have a historical event time, but a live reservation lease.
        return $synthetic
            ? UnlockLedger::msOf($doc['created_at']) < UnlockLedger::ms(CarbonImmutable::now()) - ReadingPassQuota::STALE_MS
            : UnlockLedger::msOf($doc['unlocked_at']) < UnlockLedger::ms($now) - ReadingPassQuota::STALE_MS;
    }

    /**
     * The winner of a same-article race has still not settled after the bound. Its reservation is the record for
     * this (subject, article): if the window has room without it, the winner's own decision will grant it, so the
     * article is shown without a second record or consumption; otherwise the locked page is shown.
     */
    private function unsettled(array $plan, array $winner, CarbonImmutable $now): array
    {
        if ($plan['limit'] === -1) {
            return ['canAccess' => true, 'allowance' => $this->unlimited($plan['tier'])];
        }
        $usage = $this->ledger->usage($plan['subject'], $plan['window_start'], $now->subMilliseconds(ReadingPassQuota::STALE_MS), exclude: $winner['_id']);
        if ($usage['used'] < $plan['limit']) {
            $oldest = min($usage['oldest'] ?? PHP_INT_MAX, UnlockLedger::msOf($winner['unlocked_at']));

            return ['canAccess' => true, 'allowance' => $this->allowanceFrom($plan, ['used' => $usage['used'] + 1, 'oldest' => $oldest], $now)];
        }

        return ['canAccess' => false, 'allowance' => $this->allowanceFrom($plan, $usage, $now)];
    }

    /** Longest a same-article winner can take: rebuild lock wait + one Redis timeout + margin. */
    private function settleMs(): int
    {
        $override = config('nosql.reading_pass_settle_ms');
        if ($override !== null) {
            return (int) $override;
        }
        $timeout = (float) (config('database.redis.nosql.read_timeout') ?? config('database.redis.nosql.timeout') ?? 0);
        $redisMs = $timeout > 0 ? (int) ($timeout * 1000) : self::REDIS_BUDGET_MS;

        return self::LOCK_WAIT_MS + $redisMs + self::SETTLE_MARGIN_MS;
    }

    /**
     * Transfer guest unlocks from current session to a user after login/register.
     */
    public function transferGuestUnlocks(User $user, string $sessionId): void
    {
        $userId = (string) $user->getKey();
        $userSubject = UnlockLedger::userSubject($userId);
        $guestSubject = UnlockLedger::guestSubject($sessionId);
        $owner = null;
        try {
            // Hold the user's window lock and drop the window: concurrent decisions wait for a rebuild that
            // reads the ledger after the move.
            $owner = $this->quota->lock($userSubject);
            if ($owner !== null) {
                $this->quota->forget($userSubject);
            }
        } catch (Throwable $e) {
            $this->warn('transfer', $e);
        }
        try {
            // Without the lock the moved documents are flagged, so the next decision rebuilds before deciding.
            $moved = $this->ledger->transfer($sessionId, $userId, fallback: $owner === null, now: CarbonImmutable::now());
        } finally {
            try {
                // Drop both windows whenever Redis is reachable, even if another holder had the lock.
                $this->quota->forget($userSubject, $guestSubject);
            } catch (Throwable $e) {
                $this->warn('transfer', $e);
            }
            if ($owner !== null) {
                try {
                    $this->quota->unlock($userSubject, $owner);
                } catch (Throwable) {
                    // The lock expires on its own.
                }
            }
        }
        foreach ($moved as $doc) {
            $this->history->publish($doc);
        }
    }

    // ── Private helpers ─────────────────────────────────────────

    /** @return array{tier: string, limit: int, subject: ?string, window_start: CarbonImmutable} */
    private function plan(?User $user, CarbonImmutable $now): array
    {
        [$tier, $limit, $windowStart] = match (true) {
            $user && $user->isAdmin() => ['admin', -1, $now],
            $user && $user->isPro() => ['pro', -1, $now],
            $user && $user->hasVerifiedEmail() => ['verified', 15, $now->subHours(5)],
            $user !== null => ['unverified', 8, $now->startOfDay()],
            default => ['guest', 5, $now->startOfDay()],
        };

        return [
            'tier' => $tier,
            'limit' => $limit,
            'subject' => match (true) {
                $tier === 'admin' => null,
                $user !== null => UnlockLedger::userSubject((string) $user->getKey()),
                default => UnlockLedger::guestSubject($this->sessionId()),
            },
            'window_start' => $windowStart,
        ];
    }

    /** One atomic Redis decision for a fresh reservation; MongoDB count when Redis cannot decide. */
    private function decide(array $plan, array $fields, string $id, bool $fallbackSeen, CarbonImmutable $now): array
    {
        $subject = $plan['subject'];
        $result = null;
        try {
            // A window that missed fallback grants must never decide: without a rebuild, count the ledger instead.
            if ($fallbackSeen && ! $this->rebuild($subject, $now, force: true)) {
                throw new RuntimeException('Reading Pass forced rebuild unavailable');
            }
            for ($i = 0; $i < 3 && $result === null; $i++) {
                $decision = $this->quota->decide($subject, $fields['article_id'], UnlockLedger::ms($now), UnlockLedger::ms($plan['window_start']), $plan['limit']);
                match ($decision['status']) {
                    ReadingPassQuota::NOT_BUILT => $this->rebuild($subject, $now) || throw new RuntimeException('Reading Pass window rebuild unavailable'),
                    ReadingPassQuota::STALE => $this->quota->resolve($subject, $this->ledger->resolveStale($subject, $decision['stale'], $now->subMilliseconds(ReadingPassQuota::STALE_MS))),
                    default => $result = $decision,
                };
            }
            if ($result === null) {
                throw new RuntimeException('Reading Pass decision did not settle');
            }
        } catch (Throwable $e) {
            $this->warn('decide', $e);
        }

        $fallback = $result === null;
        if ($fallback) {
            $usage = $this->ledger->usage($subject, $plan['window_start'], $now->subMilliseconds(ReadingPassQuota::STALE_MS), exclude: $id);
            $granted = $plan['limit'] === -1 || $usage['used'] < $plan['limit'];
            $result = $granted
                ? ['status' => ReadingPassQuota::GRANTED, 'used' => $usage['used'] + 1, 'oldest' => min($usage['oldest'] ?? PHP_INT_MAX, UnlockLedger::ms($now))]
                : ['status' => ReadingPassQuota::DENIED] + $usage;
        }

        if ($result['status'] === ReadingPassQuota::DENIED) {
            $this->ledger->release($id);

            return ['canAccess' => false, 'allowance' => $this->allowanceFrom($plan, $result, $now)];
        }
        $doc = $this->ledger->grant($id, $fields, $now, $plan['tier'], $fallback);
        if (! ($fields['synthetic'] ?? false)) {
            $this->history->publish($doc);
        }

        return ['canAccess' => true, 'allowance' => $this->allowanceFrom($plan, $plan['limit'] === -1 ? null : $result, $now)];
    }

    /**
     * Rebuild one subject's window from the ledger under its lock. The window is dropped before the ledger is read,
     * so no decision can be granted between the read and the publish; LOAD re-checks lock ownership and generation.
     */
    private function rebuild(string $subject, CarbonImmutable $now, bool $force = false): bool
    {
        $owner = $this->quota->lock($subject);
        if ($owner === null) {
            return false;
        }
        try {
            if (! $force && $this->quota->state($subject, 0) !== null) {
                return true; // Another request rebuilt it while this one waited.
            }
            $this->quota->forget($subject);
            $generation = $this->quota->generation();
            $window = $this->ledger->windowMembers($subject, $now->subMilliseconds(ReadingPassQuota::RETAIN_MS), $now->subMilliseconds(ReadingPassQuota::STALE_MS));
            if (! $this->quota->load($subject, $owner, $generation, $window['members'])) {
                return false;
            }
            $this->ledger->clearFallback($window['fallback']);

            return true;
        } finally {
            try {
                $this->quota->unlock($subject, $owner);
            } catch (Throwable) {
                // The lock expires on its own.
            }
        }
    }

    private function rebuildQuietly(string $subject, CarbonImmutable $now, bool $force = false): void
    {
        try {
            $this->rebuild($subject, $now, $force);
        } catch (Throwable $e) {
            $this->warn('rebuild', $e);
        }
    }

    /** @return array{used: int, oldest: ?int} Redis when the window is built, otherwise the ledger. */
    private function usage(array $plan, CarbonImmutable $now): array
    {
        try {
            $state = $this->quota->state($plan['subject'], UnlockLedger::ms($plan['window_start']));
            if ($state !== null) {
                return $state;
            }
        } catch (Throwable $e) {
            $this->warn('state', $e);
        }

        return $this->ledger->usage($plan['subject'], $plan['window_start'], $now->subMilliseconds(ReadingPassQuota::STALE_MS));
    }

    private function unlimited(string $tier): array
    {
        return [
            'tier' => $tier,
            'limit' => -1,
            'used' => 0,
            'remaining' => -1,
            'window' => $tier === 'admin' ? 'Không giới hạn cho quản trị viên' : 'Không giới hạn',
            'reset_label' => 'Không cần đặt lại',
            'next_unlock_label' => null,
        ];
    }

    /** @param  array{used: int, oldest: ?int}|null  $usage */
    private function allowanceFrom(array $plan, ?array $usage, CarbonImmutable $now): array
    {
        if ($plan['limit'] === -1) {
            return $this->unlimited($plan['tier']);
        }
        $limit = $plan['limit'];
        $used = $usage['used'];
        if ($plan['tier'] === 'verified') {
            $resetLabel = 'Cửa sổ 5 giờ gần nhất';
            $nextUnlockLabel = $used >= $limit ? $this->nextUserUnlockLabel($usage['oldest'], $now) : null;
            $window = 'Đã dùng '.$used.'/'.$limit.' lượt trong 5 giờ qua';
        } else {
            $resetLabel = 'Đặt lại lúc 00:00 giờ Việt Nam';
            $nextUnlockLabel = $used >= $limit ? 'Mở thêm lượt sau 00:00' : null;
            $window = 'Đã dùng '.$used.'/'.$limit.' lượt hôm nay';
        }

        return [
            'tier' => $plan['tier'],
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'window' => $window,
            'reset_label' => $resetLabel,
            'next_unlock_label' => $nextUnlockLabel,
        ];
    }

    private function nextUserUnlockLabel(?int $oldestInWindowMs, CarbonImmutable $now): ?string
    {
        if ($oldestInWindowMs === null) {
            return null;
        }

        $nextAt = CarbonImmutable::createFromTimestampMs($oldestInWindowMs)->addHours(5);
        $minutes = (int) max(1, $now->diffInMinutes($nextAt, false));
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return 'Lượt kế tiếp sau '.($hours > 0 ? $hours.' giờ ' : '').$mins.' phút';
    }

    private function sessionId(): string
    {
        // Session is guaranteed in web routes
        return Session::getId();
    }

    private function warn(string $operation, Throwable $e): void
    {
        // Never log subjects, session hashes or driver diagnostics.
        Log::warning('Reading Pass quota unavailable', ['operation' => $operation, 'exception_type' => $e::class]);
    }
}
