<?php

namespace App\StoryLens\Brief;

use App\Models\Article;
use App\Models\Story;

final class BriefEvidence
{
    public static function read(Story $story): array
    {
        return self::articles($story->article_ids ?? []);
    }

    public static function readForStories(array $stories): array
    {
        $idsByStory = [];
        $allIds = [];
        foreach ($stories as $story) {
            $ids = array_fill_keys($story->article_ids ?? [], true);
            $idsByStory[$story->id] = $ids;
            $allIds += $ids;
        }
        $articles = array_column(self::articles(array_keys($allIds)), null, 'id');

        return array_map(fn (array $ids) => array_values(array_intersect_key($articles, $ids)), $idsByStory);
    }

    private static function articles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Article::whereIn('_id', $ids)->get()->map(fn (Article $article) => [
            'id' => $article->id, 'title' => $article->title, 'original_url' => $article->original_url,
            'published_at' => $article->published_at->toIso8601String(), 'excerpt' => $article->description,
            'source' => $article->source_snapshot, 'category' => $article->category_snapshot,
        ])->all();
    }

    public static function forPrompt(array $evidence, int $limit): array
    {
        return array_map(fn (array $article) => [
            'id' => $article['id'],
            'source' => self::plain($article['source']['name'] ?? '', 120),
            'published_at' => $article['published_at'],
            'title' => self::plain($article['title'] ?? '', 240),
            'excerpt' => self::plain($article['excerpt'] ?? '', 800),
        ], array_slice(self::ordered($evidence), 0, $limit));
    }

    public static function extract(array $evidence): array
    {
        $points = [];
        $extra = [];
        $headlines = [];
        foreach (self::ordered($evidence) as $article) {
            $excerpt = self::plain($article['excerpt'] ?? '', 800);
            $headline = self::plain($article['title'] ?? '', 400);
            $sentences = preg_split('/(?<=[.!?…])\s+/u', $excerpt, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $first = array_shift($sentences) ?? $headline;
            self::addPoint($points, $first, $article['id']);
            $headlines[] = [$headline, $article['id']];
            foreach ($sentences as $sentence) {
                $extra[] = [$sentence, $article['id']];
            }
            if (count($points) === 4) {
                break;
            }
        }
        foreach ([...$extra, ...$headlines] as [$sentence, $id]) {
            if (count($points) >= 2) {
                break;
            }
            self::addPoint($points, $sentence, $id);
        }

        return array_values($points);
    }

    public static function plain(string $text, int $limit): string
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags(preg_replace('~</?(?:p|div|br|li|h[1-6])\b[^>]*>~i', ' ', $decoded) ?? '');

        return mb_substr(trim(preg_replace('/[\p{Z}\s]+/u', ' ', $text) ?? ''), 0, $limit);
    }

    private static function ordered(array $evidence): array
    {
        $sources = array_count_values(array_map(self::source(...), $evidence));
        // ISO timestamps may have different offsets. Compare instants first, then source frequency and ID.
        $times = array_map(fn ($article) => strtotime($article['published_at']), array_column($evidence, null, 'id'));
        usort($evidence, fn ($a, $b) => $times[$b['id']] <=> $times[$a['id']]
            ?: $sources[self::source($b)] <=> $sources[self::source($a)]
            ?: strcmp($a['id'], $b['id']));

        return $evidence;
    }

    private static function source(array $article): string
    {
        return $article['source']['uuid'] ?? $article['source']['name'] ?? '';
    }

    private static function addPoint(array &$points, string $text, string $id): void
    {
        $text = mb_substr(trim($text), 0, 400);
        if ($text !== '') {
            $points[BriefValidator::pointKey($text)] ??= ['text' => $text, 'evidence_ids' => [$id]];
        }
    }
}
