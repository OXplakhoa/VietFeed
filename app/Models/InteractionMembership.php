<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use MongoDB\Laravel\Eloquent\Model;

abstract class InteractionMembership extends Model
{
    use HasUuids;

    protected $connection = 'mongodb';

    protected $primaryKey = '_id';

    protected $fillable = ['user_id', 'article_id'];

    public function scopeReal(Builder $query): Builder
    {
        return $query->where('synthetic', '!=', true);
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

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', '_id');
    }

    public function article()
    {
        return $this->belongsTo(Article::class, 'article_id', '_id');
    }
}
