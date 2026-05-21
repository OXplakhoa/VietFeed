<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Source extends Model
{
    protected $fillable = [
        'name',
        'url',
        'feed_url',
        'logo_url',
        'category_id',
        'is_active',
        'last_fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active'       => 'boolean',
            'last_fetched_at' => 'datetime',
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function articles()
    {
        return $this->hasMany(Article::class);
    }
}
