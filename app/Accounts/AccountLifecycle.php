<?php

namespace App\Accounts;

use App\ApiTokens\ApiTokenService;
use App\Authorization\AuthorizationConflict;
use App\Billing\StripeClient;
use App\Models\User;
use App\ReadingPass\ReadingPassQuota;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;
use Throwable;

/**
 * Gate 9.7b account deletion lifecycle (request, recover). Mongo runs standalone, so every write is one conditional
 * single-document update and the user document is the linearization point: `deletion.state` goes
 * none -> pending (request), pending -> none (recover) or pending -> purging (AccountPurge claim). The durable
 * workflow (`account_deletion_workflows`, `_id` = user id) carries retry state and step checkpoints.
 */
final class AccountLifecycle
{
    public const WORKFLOWS = 'account_deletion_workflows';

    public const PENDING = 'pending';

    public const PURGING = 'purging';

    public const PURGED = 'purged';

    public const ORIGIN_SELF = 'self';

    public const ORIGIN_ADMIN = 'admin';

    /** Session key set when someone signs in to a pending account: it may only reach the recovery page. */
    public const SESSION_KEY = 'account_recovery';

    public const STRIPE_STEP = 'stripe_cancel';

    /** Subscription statuses that can still bill the customer. */
    public const BILLABLE = ['active', 'trialing', 'past_due'];

    /** A workflow written before its user document is flagged is an orphan only after this long. */
    public const ORPHAN_AFTER_MINUTES = 10;

    public function __construct(private ApiTokenService $tokens, private ReadingPassQuota $quota) {}

    public static function recoveryDays(): int
    {
        return max(1, (int) config('accounts.recovery_days', 30));
    }

    /** Pseudonym that replaces a purged user id on retained billing rows (stable, not reversible without the app key). */
    public static function pseudonym(string $userId): string
    {
        return 'deleted:'.substr(hash_hmac('sha256', $userId, (string) config('app.key')), 0, 24);
    }

    /**
     * Schedule deletion. Self-service passes the user as actor; an admin request is authorized by the caller.
     *
     * @throws AuthorizationConflict
     */
    public function request(User $actor, User $target, string $origin): void
    {
        if ($target->isAdmin()) {
            throw new AuthorizationConflict('Hãy gỡ quyền quản trị trước khi xóa tài khoản.');
        }
        if ($target->isPendingDeletion()) {
            throw new AuthorizationConflict('Tài khoản đang chờ xóa hoặc vừa thay đổi. Vui lòng thử lại.');
        }
        $id = (string) $target->id;
        $now = CarbonImmutable::now('UTC');
        $deletion = [
            'state' => self::PENDING, 'requested_at' => new UTCDateTime($now),
            'purge_after' => new UTCDateTime($now->addDays(self::recoveryDays())),
            'requested_by' => (string) $actor->id, 'origin' => $origin,
        ];
        // Workflow first: a crash before the user write leaves an orphan the worker removes; the reverse order would
        // leave a deleted account that nothing ever purges or cancels at Stripe.
        // A leftover from a crashed recovery is refreshed, never reused (stale dates, checkpoints and canceled ids).
        try {
            $this->workflows()->updateOne(
                ['_id' => $id, 'state' => ['$nin' => [self::PURGING, self::PURGED]]],
                ['$set' => $deletion + ['steps' => new \stdClass], '$unset' => ['stripe_canceled' => '', 'context' => '', 'claim' => '', 'lease_until' => '',
                    'attempts' => '', 'last_error_at' => '', 'last_error' => '']],
                ['upsert' => true],
            );
        } catch (BulkWriteException $e) {
            if ($e->getCode() !== 11000) { // purging or purged: the user write below is refused
                throw $e;
            }
        }
        $this->tokens->revokeAllForUser($id, function () use ($id, $deletion): void {
            $result = $this->users()->updateOne(
                ['_id' => self::userKey($id), 'role' => 'user', 'deletion' => ['$exists' => false]],
                ['$set' => ['deletion' => $deletion, 'remember_token' => null], '$inc' => ['api_token_epoch' => 1]],
            );
            if ($result->getMatchedCount() !== 1) {
                throw new AuthorizationConflict('Tài khoản đang chờ xóa hoặc vừa thay đổi. Vui lòng thử lại.');
            }
        });

        // Everything below is best effort: the epoch and `deletion` already deny access; the worker finishes the rest.
        $this->bestEffort('password reset token', fn () => Password::broker()->deleteToken($target));
        $this->bestEffort('reading pass quota', fn () => $this->quota->forget('u:'.$id));
        $this->bestEffort('stripe cancel', fn () => $this->cancelSubscriptions($id));
    }

    /**
     * Restore a pending account while the window is open. Self-service restores self-origin requests only; an admin restores admin-origin requests only.
     *
     * @throws AuthorizationConflict
     */
    public function recover(string $userId, bool $byAdmin = false): void
    {
        $current = $this->users()->findOne(['_id' => self::userKey($userId), 'deletion.state' => self::PENDING], ['projection' => ['deletion.requested_at' => 1]]);
        $requestedAt = $current['deletion']['requested_at'] ?? null;
        // Admins undo admin-origin requests only; a self-service request is the user's to undo.
        $filter = ['_id' => self::userKey($userId), 'deletion.state' => self::PENDING, 'deletion.requested_at' => $requestedAt,
            'deletion.origin' => $byAdmin ? self::ORIGIN_ADMIN : self::ORIGIN_SELF, '$expr' => ['$gt' => ['$deletion.purge_after', '$$NOW']]];
        if ($requestedAt === null || $this->users()->updateOne($filter, ['$unset' => ['deletion' => '']])->getModifiedCount() !== 1) {
            throw new AuthorizationConflict('Không thể khôi phục tài khoản này nữa.');
        }
        // Stripe stays canceled (documented): the user resubscribes. A crash here leaves a pending workflow for a
        // restored user, which the worker discards. Only this request's workflow is removed, never a newer one.
        $this->workflows()->deleteOne(['_id' => $userId, 'state' => self::PENDING, 'requested_at' => $requestedAt]);
    }

    /** @return array{state: string, origin: string, requested_at: CarbonImmutable, purge_after: CarbonImmutable}|null */
    public function stateOf(User $user): ?array
    {
        $deletion = $user->getAttribute('deletion');
        if ($deletion === null) {
            return null;
        }
        $deletion = (array) $deletion;

        return [
            'state' => (string) ($deletion['state'] ?? self::PENDING),
            'origin' => (string) ($deletion['origin'] ?? self::ORIGIN_SELF),
            'requested_at' => self::carbon($deletion['requested_at'] ?? null),
            'purge_after' => self::carbon($deletion['purge_after'] ?? null),
        ];
    }

    /** Identifies one deletion request, so a session from an earlier request (or none) never counts as a recovery session. */
    public function recoveryStamp(User $user): string
    {
        return (string) ($this->stateOf($user)['requested_at'] ?? CarbonImmutable::now('UTC'))->format('Uv');
    }

    /** True once the purge worker owns the account: late stream events for it must be dropped. */
    public function isPurging(?string $userId): bool
    {
        if ($userId === null || $userId === '') {
            return false;
        }

        return $this->workflows()->countDocuments(['_id' => $userId, 'state' => ['$in' => [self::PURGING, self::PURGED]]], ['limit' => 1]) === 1;
    }

    /**
     * Cancel every billable subscription once. Failures propagate so the caller (or the worker) retries; each
     * subscription is checkpointed so a retry never repeats a finished cancellation.
     */
    public function cancelSubscriptions(string $userId): int
    {
        $workflow = $this->workflows()->findOne(['_id' => $userId]);
        $done = array_map('strval', (array) ($workflow['stripe_canceled'] ?? []));
        $ids = [];
        foreach (DB::connection('mongodb')->getCollection('subscriptions')->find(
            ['user_id' => $userId, 'status' => ['$in' => self::BILLABLE]], ['projection' => ['_id' => 1]],
        ) as $subscription) {
            $ids[] = (string) $subscription['_id'];
        }
        $canceled = 0;
        foreach (array_diff($ids, $done) as $id) {
            app(StripeClient::class)->cancelSubscription($id, 'vietfeed-delete-cancel-'.$id);
            $this->workflows()->updateOne(['_id' => $userId], ['$addToSet' => ['stripe_canceled' => $id]]);
            $canceled++;
        }
        $this->workflows()->updateOne(['_id' => $userId, 'state' => ['$in' => [self::PENDING, self::PURGING]]], ['$set' => ['steps.'.self::STRIPE_STEP => new UTCDateTime]]);

        return $canceled;
    }

    /** Users created by Eloquent have an ObjectId `_id`; raw filters must use the stored type. */
    public static function userKey(string $id): ObjectId|string
    {
        return preg_match('/^[a-f0-9]{24}$/D', $id) === 1 ? new ObjectId($id) : $id;
    }

    public static function carbon(mixed $value): CarbonImmutable
    {
        return match (true) {
            $value instanceof UTCDateTime => CarbonImmutable::instance($value->toDateTime())->utc(),
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->utc(),
            is_string($value) && $value !== '' => CarbonImmutable::parse($value)->utc(),
            default => CarbonImmutable::now('UTC'),
        };
    }

    private function bestEffort(string $what, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('Account deletion step deferred to the worker', ['step' => $what, 'exception_type' => $e::class]);
        }
    }

    public function workflows(): Collection
    {
        return DB::connection('mongodb')->getCollection(self::WORKFLOWS);
    }

    private function users(): Collection
    {
        return DB::connection('mongodb')->getCollection('users');
    }
}
