<?php

namespace App\Support;

use App\Models\Article;
use Illuminate\Support\Collection;

// Gate 3: one shared per-field counter over the articles collection
// (Dashboard perCategory/perSource, Source index/health). Same numbers as the
// retired withCount subqueries.
class MongoCounts
{
    /** @return Collection<int|string, int> value => count */
    public static function byField(string $field): Collection
    {
        return collect(iterator_to_array(Article::raw(fn ($collection) => $collection->aggregate([
            ['$group' => ['_id' => '$'.$field, 'n' => ['$sum' => 1]]],
        ]))))->pluck('n', 'id');
    }
}
