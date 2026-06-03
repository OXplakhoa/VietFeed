<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleUnlock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Session;

class ReadingPassService
{
    /**
     * Allowance info for the current user/session context.
     *
     * @return array{tier: string, limit: int, used: int, remaining: int, window: string, reset_label: string, next_unlock_label: ?string}
     */
    public function allowance(?User $user): array
    {
        $now = CarbonImmutable::now('Asia/Ho_Chi_Minh');

        if ($user && $user->isAdmin()) {
            return [
                'tier' => 'admin',
                'limit' => -1,
                'used' => 0,
                'remaining' => -1,
                'window' => 'Không giới hạn cho quản trị viên',
                'reset_label' => 'Không cần đặt lại',
                'next_unlock_label' => null,
            ];
        }

        if ($user && $this->isPro($user)) {
            return [
                'tier' => 'pro',
                'limit' => -1,
                'used' => 0,
                'remaining' => -1,
                'window' => 'Không giới hạn',
                'reset_label' => 'Không cần đặt lại',
                'next_unlock_label' => null,
            ];
        }

        if ($user && $user->hasVerifiedEmail()) {
            $limit = 15;
            $windowStart = $now->subHours(5);
            $used = $this->userUnlockCountSince($user, $windowStart);
            $resetLabel = 'Cửa sổ 5 giờ gần nhất';
            $nextUnlockLabel = $used >= $limit ? $this->nextUserUnlockLabel($user, $windowStart, $now) : null;
        } elseif ($user) {
            $limit = 8;
            $windowStart = $now->startOfDay();
            $used = $this->userUnlockCountSince($user, $windowStart);
            $resetLabel = 'Đặt lại lúc 00:00 giờ Việt Nam';
            $nextUnlockLabel = $used >= $limit ? 'Mở thêm lượt sau 00:00' : null;
        } else {
            $limit = 5;
            $windowStart = $now->startOfDay();
            $used = $this->guestUnlockCountSince($windowStart);
            $resetLabel = 'Đặt lại lúc 00:00 giờ Việt Nam';
            $nextUnlockLabel = $used >= $limit ? 'Mở thêm lượt sau 00:00' : null;
        }

        return [
            'tier' => $user
                ? ($user->hasVerifiedEmail() ? 'verified' : 'unverified')
                : 'guest',
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'window' => match (true) {
                $user && $user->hasVerifiedEmail() => 'đã dùng '.$used.'/'.$limit.' lượt trong 5 giờ qua',
                $user => 'đã dùng '.$used.'/'.$limit.' lượt hôm nay',
                default => 'đã dùng '.$used.'/'.$limit.' lượt hôm nay',
            },
            'reset_label' => $resetLabel,
            'next_unlock_label' => $nextUnlockLabel,
        ];
    }

    /**
     * Check whether the given article can be accessed, and if so, record the unlock.
     *
     * @return array{canAccess: bool, allowance: array}
     */
    public function accessOrLock(Article $article, ?User $user): array
    {
        $allowance = $this->allowance($user);

        // Admin support/debug access should not consume or inflate Reading Pass metrics.
        if ($user?->isAdmin()) {
            return ['canAccess' => true, 'allowance' => $allowance];
        }

        // Pro / unlimited
        if ($allowance['limit'] === -1) {
            $this->recordUnlock($article, $user);

            return ['canAccess' => true, 'allowance' => $allowance];
        }

        // Already unlocked this article before?
        if ($this->alreadyUnlocked($article, $user)) {
            return ['canAccess' => true, 'allowance' => $allowance];
        }

        // Check remaining quota
        if ($allowance['remaining'] <= 0) {
            return ['canAccess' => false, 'allowance' => $allowance];
        }

        // Consume one unlock
        $this->recordUnlock($article, $user);

        // Refresh allowance after consumption
        $allowance = $this->allowance($user);

        return ['canAccess' => true, 'allowance' => $allowance];
    }

    /**
     * Transfer guest unlocks from current session to a user after login/register.
     */
    public function transferGuestUnlocks(User $user, string $sessionId): void
    {
        $guestUnlocks = ArticleUnlock::where('session_id', $sessionId)
            ->whereNull('user_id')
            ->get();

        foreach ($guestUnlocks as $unlock) {
            // If user already has an unlock for this article, remove the guest one
            $existing = ArticleUnlock::where('article_id', $unlock->article_id)
                ->where('user_id', $user->id)
                ->exists();

            if ($existing) {
                $unlock->delete();
            } else {
                $unlock->update([
                    'user_id' => $user->id,
                    'session_id' => null,
                ]);
            }
        }
    }

    // ── Private helpers ─────────────────────────────────────────

    private function isPro(User $user): bool
    {
        // Stubbed until Phase 3 — matches User::isPro()
        return $user->isPro();
    }

    private function alreadyUnlocked(Article $article, ?User $user): bool
    {
        if ($user) {
            return ArticleUnlock::where('article_id', $article->id)
                ->where('user_id', $user->id)
                ->exists();
        }

        return ArticleUnlock::where('article_id', $article->id)
            ->where('session_id', $this->sessionId())
            ->exists();
    }

    private function recordUnlock(Article $article, ?User $user): void
    {
        if ($user) {
            ArticleUnlock::firstOrCreate([
                'article_id' => $article->id,
                'user_id' => $user->id,
            ], [
                'session_id' => null,
                'unlocked_at' => now(),
            ]);
        } else {
            ArticleUnlock::firstOrCreate([
                'article_id' => $article->id,
                'session_id' => $this->sessionId(),
            ], [
                'user_id' => null,
                'unlocked_at' => now(),
            ]);
        }
    }

    private function userUnlockCountSince(User $user, CarbonImmutable $since): int
    {
        return ArticleUnlock::where('user_id', $user->id)
            ->where('unlocked_at', '>=', $since)
            ->count();
    }

    private function guestUnlockCountSince(CarbonImmutable $since): int
    {
        return ArticleUnlock::where('session_id', $this->sessionId())
            ->whereNull('user_id')
            ->where('unlocked_at', '>=', $since)
            ->count();
    }

    private function nextUserUnlockLabel(User $user, CarbonImmutable $windowStart, CarbonImmutable $now): ?string
    {
        $oldestInWindow = ArticleUnlock::where('user_id', $user->id)
            ->where('unlocked_at', '>=', $windowStart)
            ->orderBy('unlocked_at')
            ->value('unlocked_at');

        if (! $oldestInWindow) {
            return null;
        }

        $nextAt = CarbonImmutable::parse($oldestInWindow)->addHours(5);
        $minutes = max(1, $now->diffInMinutes($nextAt, false));
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return 'Lượt kế tiếp sau '.($hours > 0 ? $hours.' giờ ' : '').$mins.' phút';
    }

    private function sessionId(): string
    {
        // Session is guaranteed in web routes
        return Session::getId();
    }
}
