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
        return $this->belongsTo(Source::class, 'source_id', '_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id', '_id');
    }

    public function comments()
    {
        // Gate 9.5: Mongo → Mongo, keyed by the article's string id.
        return $this->hasMany(Comment::class, 'article_id', '_id');
    }

    public function bookmarks()
    {
        return $this->hasMany(Bookmark::class, 'article_id', '_id');
    }

    public function boosts()
    {
        return $this->hasMany(Boost::class, 'article_id', '_id');
    }

    public function unlocks()
    {
        return $this->hasMany(ArticleUnlock::class);
    }

    protected function sourceName(): Attribute
    {
        return Attribute::make(get: fn () => $this->source?->name ?? $this->source_snapshot['name'] ?? '—');
    }

    protected function categoryName(): Attribute
    {
        return Attribute::make(get: fn () => $this->category?->name ?? $this->category_snapshot['name'] ?? '—');
    }

    protected function categoryUrl(): Attribute
    {
        // A snapshot slug alone does not establish a public category route.
        return Attribute::make(get: fn () => $this->category?->is_active && $this->category->slug
            ? route('categories.show', $this->category->slug) : null);
    }

    protected function readingTime(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) max(1, ceil(str_word_count(strip_tags($this->description ?? '')) / 200))
        );
    }
}
