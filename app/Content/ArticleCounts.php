<?php

namespace App\Content;

use App\Models\Bookmark;
use App\Models\Boost;
use Illuminate\Support\Collection;

class ArticleCounts
{
    public static function attach(Collection $articles): void
    {
        if ($articles->isEmpty()) {
            return;
        }
        $ids = $articles->map->getKey()->all();
        foreach (['bookmarks' => Bookmark::class, 'boosts' => Boost::class] as $name => $model) {
            $counts = collect();
            foreach (array_chunk($ids, 500) as $chunk) {
                $counts = $counts->union($model::select('article_id')->selectRaw('COUNT(*) as n')
                    ->whereIn('article_id', $chunk)->groupBy('article_id')->pluck('n', 'article_id'));
            }
            foreach ($articles as $article) {
                $article->setAttribute($name.'_count', (int) ($counts[$article->getKey()] ?? 0));
            }
        }
    }
}
