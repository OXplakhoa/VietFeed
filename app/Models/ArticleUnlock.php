<?php

namespace App\Models;

use App\ReadingPass\UnlockLedger;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Gate 9.5 slice 3b: one MongoDB document per (subject, article) — the durable Reading Pass ledger that answers
 * "already unlocked" and rebuilds the Redis quota windows. The request path writes through UnlockLedger; this
 * model serves reads, seeders and fixtures. A raw `session_id` is never stored: it becomes `session_hash`.
 */
class ArticleUnlock extends Model
{
    use HasUuids;

    protected $connection = 'mongodb';

    protected $collection = 'article_unlocks';

    protected $primaryKey = '_id';

    protected $fillable = [
        'article_id',
        'user_id',
        'session_id',
        'unlocked_at',
    ];

    protected function casts(): array
    {
        return [
            'unlocked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $unlock) {
            $sessionId = $unlock->attributes['session_id'] ?? null;
            unset($unlock->attributes['session_id']);
            $userId = $unlock->attributes['user_id'] ?? null;
            if ($userId === null) {
                $unlock->attributes['user_id'] = null;
                $unlock->attributes['session_hash'] ??= $sessionId === null ? null : UnlockLedger::sessionHash((string) $sessionId);
            }
            $unlock->attributes['subject'] ??= $userId !== null
                ? UnlockLedger::userSubject((string) $userId)
                : UnlockLedger::GUEST_PREFIX.$unlock->attributes['session_hash'];
            $unlock->attributes['state'] ??= UnlockLedger::GRANTED;
            $unlock->attributes['origin'] ??= 'seed';
            $unlock->unlocked_at ??= now();
        });
    }

    public function setAttribute($key, $value)
    {
        if (in_array($key, ['user_id', 'article_id'], true) && $value !== null) {
            $value = (string) $value;
        } elseif ($key === 'legacy_sql_id' && $value !== null) {
            $value = (int) $value;
        }

        return parent::setAttribute($key, $value);
    }

    public function article()
    {
        return $this->belongsTo(Article::class, 'article_id', '_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', '_id');
    }
}
