<?php

namespace App\Models;

use Illuminate\Support\Str;
use MongoDB\Laravel\Eloquent\Model;

// Gate 3 T3: minimal Story document (id/title/status). No clustering/pipeline —
// StoryLens slices own that. UUIDv7 string IDs like Article.
class Story extends Model
{
    protected $connection = 'mongodb';

    protected $primaryKey = '_id';

    protected static function booted(): void
    {
        static::creating(fn (Story $story) => $story->_id ??= (string) Str::uuid7());
    }

    protected $fillable = [
        'title',
        'status',
    ];
}
