<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $fillable = [
        'reporter_id',
        'comment_id',
        'admin_id',
        'reason',
        'description',
        'status',
        'comment_body',
        'comment_user_id',
        'comment_article_id',
        'decided_at',
        'decision_note',
        'sanction_id',
    ];

    public const STATUSES = ['pending', 'actioned', 'dismissed', 'false_report'];

    public const REASONS = ['hate_speech', 'harassment', 'spam', 'misinformation', 'sexual_content', 'other'];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function comment()
    {
        return $this->belongsTo(Comment::class, 'comment_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function sanction()
    {
        return $this->belongsTo(Sanction::class, 'sanction_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeActioned($query)
    {
        return $query->where('status', 'actioned');
    }

    public function scopeDismissed($query)
    {
        return $query->where('status', 'dismissed');
    }

    public function scopeFalseReport($query)
    {
        return $query->where('status', 'false_report');
    }

    public function reasonLabel(): string
    {
        return match ($this->reason) {
            'hate_speech' => 'Phát ngôn thù địch',
            'harassment' => 'Quấy rối / đe dọa',
            'spam' => 'Spam / quảng cáo',
            'misinformation' => 'Thông tin sai lệch',
            'sexual_content' => 'Nội dung tình dục',
            'other' => 'Khác',
            default => 'Không xác định',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Chờ duyệt',
            'actioned' => 'Đã xử lý',
            'dismissed' => 'Bác bỏ',
            'false_report' => 'Báo cáo sai',
            default => 'Không xác định',
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'pending' => '#f59e0b',
            'actioned' => '#22c55e',
            'dismissed' => '#6b7280',
            'false_report' => '#ef4444',
            default => '#6b7280',
        };
    }
}
