<?php

namespace App\Content;

use App\Models\Bookmark;
use App\Models\Boost;
use App\Support\MongoCounts;
use Illuminate\Support\Collection;

class ArticleCounts
{
    public static function attach(Collection $articles, array $groupedCounts = []): void
    {
        if ($articles->isEmpty()) {
            return;
        }
        $ids = $articles->map->getKey()->all();
        foreach (['bookmarks' => Bookmark::class, 'boosts' => Boost::class] as $name => $model) {
            $counts = $groupedCounts[$name] ?? MongoCounts::group($model, 'article_id', $ids);
            foreach ($articles as $article) {
                $article->setAttribute($name.'_count', (int) ($counts[$article->getKey()] ?? 0));
            }
        }
    }
}
