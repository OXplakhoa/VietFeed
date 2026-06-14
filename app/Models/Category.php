<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
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
        return $this->hasMany(Source::class);
    }

    public function articles()
    {
        return $this->hasMany(Article::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'category_user');
    }
}
