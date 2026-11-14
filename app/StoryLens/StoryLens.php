<?php

namespace App\StoryLens;

use App\Models\Article;
use App\Models\Story;
use App\StoryProcessing\StoryGraph;
use App\StoryProcessing\StoryPipeline;
use App\StoryProcessing\StoryTimeline;

final class StoryLens
{
    public function read(string $id): array
    {
        $story = Story::findOrFail($id);
        $evidence = Article::whereIn('_id', ($story->article_ids ?? []))->get()->map(fn (Article $article) => [
            'id' => $article->id, 'title' => $article->title, 'original_url' => $article->original_url,
            'published_at' => $article->published_at->toIso8601String(), 'excerpt' => $article->description,
            'source' => $article->source_snapshot, 'category' => $article->category_snapshot,
        ])->all();

        $sections = StoryPipeline::sections($story);
        foreach (['timeline', 'graph'] as $section) {
            $sections[$section]['data'] = [];
            if ($sections[$section]['state'] !== 'ready') {
                continue;
            }
            try {
                $sections[$section]['data'] = $section === 'timeline'
                    ? app(StoryTimeline::class)->events($id)
                    : app(StoryGraph::class)->sources($id);
            } catch (\Throwable) {
                // A derived read outage must never discard canonical evidence.
                $sections[$section]['state'] = 'failed';
                $sections[$section]['error'] = 'Derived read unavailable; retry or replay projection';
            }
        }

        return ['story' => ['id' => $story->id, 'title' => $story->title, 'version' => $story->version ?? 0, 'article_count' => $story->article_count ?? count($evidence), 'source_count' => $story->source_count ?? 0], 'evidence' => $evidence, 'sections' => $sections];
    }
}
