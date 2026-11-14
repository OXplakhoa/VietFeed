<?php

namespace App\StoryLens;

use App\Models\Article;
use App\Models\Story;
use App\StoryProcessing\StoryGraph;
use App\StoryProcessing\StoryPipeline;
use App\StoryProcessing\StoryTimeline;
use Carbon\CarbonImmutable;

final class StoryLens
{
    /** Safe presentation contract; operational error details never cross HTTP. */
    public function readForPage(string $id): array
    {
        $read = $this->read($id);
        foreach ($read['evidence'] as &$article) {
            $article['published_at_display'] = CarbonImmutable::parse($article['published_at'])->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i');
            $url = $article['original_url'];
            $article['original_url'] = is_string($url)
                && filter_var($url, FILTER_VALIDATE_URL)
                && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)
                ? $url : null;
        }
        unset($article);
        $titles = array_column($read['evidence'], 'title', 'id');
        foreach (['timeline', 'graph'] as $section) {
            $value = $read['sections'][$section];
            $read['sections'][$section] = [
                'state' => $value['state'],
                'projected_version' => $value['projected_version'],
                'data' => [],
            ];
            if ($value['state'] !== 'ready') {
                continue;
            }
            try {
                $data = $value['data'];
                if ($section === 'timeline') {
                    foreach ($data as &$row) {
                        if (! is_scalar($row['article_id']) || ! is_string($row['occurred_at']) || $row['occurred_at'] === '') {
                            throw new \UnexpectedValueException('Invalid timeline row');
                        }
                        $row['article_title'] = $titles[$row['article_id']] ?? 'Bài viết chưa có trong danh sách nguồn';
                        $time = CarbonImmutable::parse($row['occurred_at']);
                        $row['occurred_at'] = $time->toIso8601String();
                        $row['occurred_at_display'] = $time->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i');
                    }
                    unset($row);
                }
                $read['sections'][$section]['data'] = $data;
            } catch (\Throwable) {
                // Shaping failures have the same presentation as a derived read outage.
                unset($row);
                $read['sections'][$section]['state'] = 'failed';
            }
        }

        return $read;
    }

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
