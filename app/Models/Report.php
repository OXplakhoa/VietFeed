<?php

namespace App\Models;

use App\Moderation\ModerationCases;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Gate 9b: one report document per reporter and comment on MongoDB (UUIDv7 `_id`).
 * Every created report is registered on its per-comment case document, which is the single
 * point of atomicity for deciding the case (see {@see ModerationCases}).
 */
class Report extends Model
{
    use HasUuids;

    protected $connection = 'mongodb';

    protected $primaryKey = '_id';

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

    protected $attributes = ['status' => 'pending'];

    public const STATUSES = ['pending', 'actioned', 'dismissed', 'false_report'];

    public const REASONS = ['hate_speech', 'harassment', 'spam', 'misinformation', 'sexual_content', 'other'];

    protected static function booted(): void
    {
        // The case must exist before the report so the report carries its case id.
        static::creating(function (Report $report) {
            $report->case_id ??= app(ModerationCases::class)->ensureFor($report);
        });
        static::created(fn (Report $report) => app(ModerationCases::class)->register($report));
    }

    protected function casts(): array
    {
        return [
            'comment_id' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    // Related SQL/Mongo models keep their own connection.
    protected function newRelatedInstance($class)
    {
        return new $class;
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
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

    public static function severityOf(?string $reason): int
    {
        return match ($reason) {
            'hate_speech', 'harassment', 'sexual_content' => 2,
            'misinformation' => 1,
            default => 0,
        };
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
