<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Gate 9.5 slice 2: comments (incl. replies and the moderation hide fields) live on MongoDB with a
 * UUIDv7 string `_id`. `is_hidden` / `hidden_by_sanction_id` are not fillable: only the moderation
 * module writes them, with single-document conditional updates (see ModerationService::applyHide).
 */
class Comment extends Model
{
    use HasUuids;

    protected $connection = 'mongodb';

    protected $primaryKey = '_id';

    protected $fillable = ['user_id', 'article_id', 'parent_id', 'body'];

    // Mongo has no column defaults: a new comment is visible and top-level unless told otherwise.
    protected $attributes = ['parent_id' => null, 'is_hidden' => false, 'hidden_by_sanction_id' => null];

    private const STRING_REFS = ['user_id', 'article_id', 'parent_id', 'hidden_by_sanction_id'];

    protected static function booted(): void
    {
        // Replaces the SQL `parent_id` FK cascade. Descendants are removed before the comment itself, so
        // an interrupted delete leaves the parent in place (retry deletes the rest) and never orphans a
        // reply. Reports and their snapshots are kept (9a rule 13).
        static::deleting(function (Comment $comment) {
            $ids = $comment->descendantIds();
            foreach (array_chunk($ids, 500) as $chunk) {
                static::whereIn('_id', $chunk)->delete();
            }
        });
    }

    protected function casts(): array
    {
        return ['is_hidden' => 'boolean'];
    }

    /** Type normalization on write: cross-store references are strings, the hide flag a boolean. */
    public function setAttribute($key, $value)
    {
        if (in_array($key, self::STRING_REFS, true)) {
            $value = $value === null || $value === '' ? null : (string) $value;
        } elseif ($key === 'is_hidden') {
            $value = (bool) $value;
        }

        return parent::setAttribute($key, $value);
    }

    // Related models keep their own connection.
    protected function newRelatedInstance($class)
    {
        return new $class;
    }

    /** @return list<string> every reply below this comment, any depth (breadth-first, cycle safe) */
    public function descendantIds(): array
    {
        $seen = [(string) $this->getKey() => true];
        $all = [];
        $frontier = [(string) $this->getKey()];
        while ($frontier !== []) {
            $next = [];
            foreach (array_chunk($frontier, 500) as $chunk) {
                foreach (static::whereIn('parent_id', $chunk)->get(['_id']) as $reply) {
                    $id = (string) $reply->getKey();
                    if (! isset($seen[$id])) {
                        $seen[$id] = true;
                        $next[] = $id;
                    }
                }
            }
            $all = array_merge($all, $next);
            $frontier = $next;
        }

        return $all;
    }

    public function user()
    {
        // Explicit key: the guessed FK would be `user__id` (Gate 9a D1).
        return $this->belongsTo(User::class, 'user_id', '_id');
    }

    public function article()
    {
        return $this->belongsTo(Article::class, 'article_id', '_id');
    }

    public function parent()
    {
        return $this->belongsTo(Comment::class, 'parent_id', '_id');
    }

    public function replies()
    {
        return $this->hasMany(Comment::class, 'parent_id', '_id');
    }

    public function reports()
    {
        return $this->hasMany(Report::class, 'comment_id', '_id');
    }

    public function scopeVisible($query)
    {
        return $query->where('is_hidden', false);
    }

    /** Hidden comments stay visible to their author and to admins only. */
    public function scopeVisibleTo($query, ?User $viewer)
    {
        if ($viewer?->isAdmin()) {
            return $query;
        }

        return $query->where(function ($q) use ($viewer) {
            $q->where('is_hidden', false);
            if ($viewer) {
                $q->orWhere('user_id', (string) $viewer->id);
            }
        });
    }

    public function scopeHidden($query)
    {
        return $query->where('is_hidden', true);
    }
}
