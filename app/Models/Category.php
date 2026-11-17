<?php

namespace App\Models;

use App\Content\CategoryList;
use App\Models\Concerns\NormalizesConfigTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use MongoDB\Laravel\Eloquent\Model;

class Category extends Model
{
    use NormalizesConfigTypes;

    protected $connection = 'mongodb';

    protected $primaryKey = '_id';

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->_id ??= (string) Str::uuid7());
        static::saved(fn () => app(CategoryList::class)->forget());
        static::deleted(fn () => app(CategoryList::class)->forget());
    }

    protected function newRelatedInstance($class)
    {
        return new $class;
    }

    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'slug', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function sources()
    {
        return $this->hasMany(Source::class, 'category_id', '_id');
    }

    public function articles()
    {
        return $this->hasMany(Article::class, 'category_id', '_id');
    }
}
