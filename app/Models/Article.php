<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    protected $fillable = [
        'source_id',
        'category_id',
        'title',
        'slug',
        'description',
        'image_url',
        'original_url',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function source()
    {
        return $this->belongsTo(Source::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function bookmarks()
    {
        return $this->hasMany(Bookmark::class);
    }

    protected function readingTime(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) max(1, ceil(str_word_count(strip_tags($this->description ?? '')) / 200))
        );
    }
}
