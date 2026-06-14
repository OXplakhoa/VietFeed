<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sanction extends Model
{
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
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
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

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
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
        if (! $this->is_active) {
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
