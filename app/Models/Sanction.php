<?php

namespace App\Models;

use App\ApiTokens\ApiTokenService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

class Sanction extends Model
{
    protected int $apiTokenBanTransition = 0;

    protected static function booted(): void
    {
        static::updating(function (Sanction $sanction) {
            if ($sanction->isDirty('user_id')) {
                throw new LogicException('Sanction user_id cannot be changed after creation.');
            }
        });
        static::saving(function (Sanction $sanction) {
            $effective = $sanction->isEffectiveBan($sanction->getAttributes());
            $wasEffective = $sanction->isEffectiveBan($sanction->getRawOriginal());
            $sanction->apiTokenBanTransition = $effective === $wasEffective ? 0 : ($effective ? 1 : -1);
            if ($sanction->apiTokenBanTransition === 1) {
                // Abort before SQL if Mongo cannot establish the pre-write epoch.
                $sanction->bumpApiTokenEpoch();
            }
        });
        static::saved(function (Sanction $sanction) {
            $transition = $sanction->apiTokenBanTransition;
            $sanction->apiTokenBanTransition = 0;
            if ($transition === 0) {
                return;
            }
            try {
                // Fence logins that read the pre-write epoch before SQL persisted.
                $sanction->bumpApiTokenEpoch();
            } catch (Throwable $error) {
                Log::warning('Sanction token epoch update failed', [
                    'operation' => $transition === 1 ? 'ban_saved' : 'ban_lifted',
                    'exception_type' => $error::class,
                ]);
            }
            app(ApiTokenService::class)->cleanupForUser((string) $sanction->user_id);
        });
        static::deleted(function (Sanction $sanction) {
            if (! $sanction->isEffectiveBan($sanction->getRawOriginal())) {
                return;
            }
            try {
                $sanction->bumpApiTokenEpoch();
            } catch (Throwable $error) {
                Log::warning('Sanction token epoch update failed', [
                    'operation' => 'ban_lifted',
                    'exception_type' => $error::class,
                ]);
            }
            app(ApiTokenService::class)->cleanupForUser((string) $sanction->user_id);
        });
    }

    private function isEffectiveBan(array $attributes): bool
    {
        return in_array($attributes['type'] ?? null, ['temporary_ban', 'permanent_ban'])
            && ($attributes['is_active'] ?? false)
            && (! isset($attributes['expires_at']) || $this->asDateTime($attributes['expires_at'])->isFuture());
    }

    private function bumpApiTokenEpoch(): void
    {
        User::normalizeApiTokenEpoch((string) $this->user_id);
        User::where('_id', $this->user_id)->increment('api_token_epoch');
    }

    protected $fillable = [
        'user_id',
        'admin_id',
        'report_id',
        'type',
        'reason',
        'expires_at',
        'is_active',
        'appeal_message',
        'appeal_status',
        'revoked_at',
        'revoked_by',
        'revoke_note',
        'revoked_via',
        'appealed_at',
        'appeal_decided_at',
        'appeal_decided_by',
        'appeal_decision_note',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'revoked_at' => 'datetime',
            'appealed_at' => 'datetime',
            'appeal_decided_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function report()
    {
        return $this->belongsTo(Report::class);
    }

    public function revoker()
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /** Lapsed on its own: still flagged active, never revoked, past its expiry. */
    public function scopeExpired($query)
    {
        return $query->where('is_active', true)->whereNull('revoked_at')
            ->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    public function scopeRevoked($query)
    {
        return $query->whereNotNull('revoked_at');
    }

    public function scopeWarnings($query)
    {
        return $query->where('type', 'warning');
    }

    public function scopeMutes($query)
    {
        return $query->where('type', 'mute');
    }

    public function scopeTemporaryBans($query)
    {
        return $query->where('type', 'temporary_ban');
    }

    public function scopePermanentBans($query)
    {
        return $query->where('type', 'permanent_ban');
    }

    public function scopePendingAppeals($query)
    {
        return $query->where('appeal_status', 'pending');
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'warning' => 'Cảnh cáo',
            'mute' => 'Cấm bình luận',
            'temporary_ban' => 'Cấm tạm thời',
            'permanent_ban' => 'Cấm vĩnh viễn',
            default => 'Không xác định',
        };
    }

    public function appealStatusLabel(): string
    {
        return match ($this->appeal_status) {
            'none' => 'Chưa kháng cáo',
            'pending' => 'Chờ duyệt',
            'approved' => 'Đã chấp nhận',
            'denied' => 'Bị từ chối',
            default => 'Không xác định',
        };
    }

    public function typeColor(): string
    {
        return match ($this->type) {
            'warning' => '#f59e0b',
            'mute' => '#f472b6',
            'temporary_ban' => '#ef4444',
            'permanent_ban' => '#7f1d1d',
            default => '#6b7280',
        };
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isCurrentlyActive(): bool
    {
        if (! $this->is_active || $this->revoked_at !== null) {
            return false;
        }

        if ($this->type === 'permanent_ban') {
            return true;
        }

        if ($this->type === 'warning') {
            return true;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'Đã thu hồi',
            ! $this->is_active => 'Hết hiệu lực',
            $this->isExpired() => 'Hết hạn',
            default => 'Đang hiệu lực',
        };
    }

    public function durationLabel(): string
    {
        if ($this->type === 'warning' || $this->type === 'permanent_ban') {
            return $this->type === 'warning' ? 'Vĩnh viễn' : 'Vĩnh viễn';
        }

        if ($this->expires_at === null) {
            return 'Không xác định';
        }

        $days = (int) now()->diffInDays($this->expires_at, false);

        if ($days <= 0) {
            return 'Hết hạn';
        }

        return $days.' ngày';
    }
}
