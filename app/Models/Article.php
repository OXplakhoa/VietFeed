<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;
use MongoDB\Laravel\Eloquent\Model;

class Article extends Model
{
    // Gate 3: canonical store is Mongo (T3). SQL articles table kept until all refs migrate.
    protected $connection = 'mongodb';

    protected $primaryKey = '_id';

    protected static function booted(): void
    {
        // Canonical UUIDv7 string IDs generated in Laravel before persistence.
        static::creating(fn (Article $article) => $article->_id ??= (string) Str::uuid7());
    }

    // Same cross-store pin as User (T2): related SQL models stay on default SQL.
    protected function newRelatedInstance($class)
    {
        return new $class;
    }

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

    public function boosts()
    {
        return $this->hasMany(Boost::class);
    }

    public function unlocks()
    {
        return $this->hasMany(ArticleUnlock::class);
    }

    protected function readingTime(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) max(1, ceil(str_word_count(strip_tags($this->description ?? '')) / 200))
        );
    }
}
