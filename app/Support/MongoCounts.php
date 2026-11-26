<?php

namespace App\Support;

use App\Models\Article;
use App\Models\InteractionMembership;
use Illuminate\Support\Collection;
use MongoDB\Laravel\Eloquent\Model;

// Gate 3: one shared per-field counter over a Mongo collection (Dashboard perCategory/perSource,
// Source index/health; Gate 9.5: comments per article / per user). Same numbers as the retired
// withCount / SQL GROUP BY queries, one aggregate per call (no N+1).
class MongoCounts
{
    /** @return Collection<int|string, int> value => count */
    public static function byField(string $field): Collection
    {
        return self::group(Article::class, $field);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>|null  $in  restrict to these field values (e.g. the ids on one page)
     * @return Collection<int|string, int> value => count
     */
    public static function group(string $model, string $field, ?array $in = null): Collection
    {
        if ($in === []) {
            return collect();
        }
        $match = $field === 'article_id' && is_subclass_of($model, InteractionMembership::class)
            ? ['synthetic' => ['$ne' => true]]
            : [];
        if ($in !== null) {
            $match[$field] = ['$in' => array_values($in)];
        }
        $pipeline = $match === [] ? [] : [['$match' => $match]];
        $pipeline[] = ['$group' => ['_id' => '$'.$field, 'n' => ['$sum' => 1]]];

        return collect(iterator_to_array($model::raw(fn ($collection) => $collection->aggregate($pipeline))))->pluck('n', 'id');
    }
}
