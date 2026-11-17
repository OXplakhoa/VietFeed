<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $fillable = ['user_id', 'article_id', 'parent_id', 'body'];

    protected function casts(): array
    {
        return ['is_hidden' => 'boolean'];
    }

    public function user()
    {
        // Explicit key: the guessed FK would be `user__id` (relation + related primary key `_id`),
        // which never matches and made every comment author null (Gate 9a D1).
        return $this->belongsTo(User::class, 'user_id');
    }

    public function article()
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function parent()
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(Comment::class, 'parent_id');
    }

    public function reports()
    {
        return $this->hasMany(Report::class, 'comment_id');
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
