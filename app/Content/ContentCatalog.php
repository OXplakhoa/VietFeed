<?php

namespace App\Content;

use App\Models\Article;
use App\Models\Category;
use App\Models\Source;
use App\Models\Story;
use App\StoryLens\Brief\Briefs;
use App\StoryProcessing\StoryPipeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** Canonical content operations; no SQL Source/Category joins on this tracer path. */
final class ContentCatalog
{
    public function __construct(private StoryPipeline $pipeline, private Briefs $briefs) {}

    public function ingest(array $fixture): int
    {
        Validator::make($fixture, [
            'format_version' => 'required|in:1',
            'items' => 'required|array|min:1|max:15',
            'items.*.story_key' => 'required|string|max:160',
            'items.*.title' => 'required|string|max:1000',
            'items.*.link' => 'required|url:http,https|max:2000',
            'items.*.published_at' => 'required|date',
            'items.*.excerpt' => 'present|string|max:200',
            'items.*.source.name' => 'required|string|max:200',
            'items.*.source.url' => 'required|url:http,https',
            'items.*.source.feed_url' => 'required|url:http,https',
            'items.*.category.slug' => 'required|string|max:160',
            'items.*.category.name' => 'required|string|max:200',
        ])->validate();
        $this->indexes();
        $count = 0;
        $versions = [];
        try {
            foreach ($fixture['items'] as $item) {
                $event = $this->attach($item);
                if ($event === null) {
                    continue;
                }
                $versions[$event['aggregate_id']] = $event['aggregate_version'];
                $this->pipeline->publish($event);
                $count++;
            }
        } finally {
            // Keep brief dispatch for commits made before a projection failure.
            $position = 0;
            foreach ($versions as $storyId => $version) {
                $this->briefs->queue($storyId, $version, position: $position++);
            }
        }

        return $count;
    }

    private function attach(array $item): ?array
    {
        $story = Story::where('story_key', $item['story_key'])->first();
        $article = Article::where('original_url', $item['link'])->first();
        if ($article && $story && in_array($article->id, $story->article_ids ?? [], true)) {
            foreach ($this->missingCanonicalIds($article, $item) as $field => $id) {
                Article::whereKey($article->id)->whereNull($field)->update([$field => $id]);
            }

            return null;
        }
        if ($article && $article->story_id && (($story && $article->story_id !== $story->id) || ($article->story_key && $article->story_key !== $item['story_key']))) {
            throw new \InvalidArgumentException('URL already belongs to another Story');
        }
        $story ??= new Story;
        if (! $story->exists) {
            $story->forceFill(['title' => $item['title'], 'story_key' => $item['story_key'], 'status' => 'active', 'version' => 0, 'article_ids' => [], 'events' => []]);
            // Assign before either document is persisted.
            $story->_id = $article?->story_id ?: (string) Str::uuid7();
        }
        $article ??= new Article;
        $source = $item['source'];
        $source['uuid'] = Uuid::uuid5(Uuid::NAMESPACE_URL, 'vietfeed:source:'.$source['feed_url'])->toString();
        $category = $item['category'];
        $category['uuid'] = Uuid::uuid5(Uuid::NAMESPACE_URL, 'vietfeed:category:'.$category['slug'])->toString();
        $article->forceFill([
            'title' => $item['title'], 'original_url' => $item['link'],
            'slug' => Str::slug($item['title']).'-'.substr(hash('sha256', $item['link']), 0, 12),
            'published_at' => CarbonImmutable::parse($item['published_at']),
            'description' => $item['excerpt'], 'source_snapshot' => $source,
            'category_snapshot' => $category, 'story_id' => $story->id, 'story_key' => $item['story_key'],
        ] + $this->missingCanonicalIds($article, $item))->save();
        $ids = [...$story->article_ids, $article->id];
        $evidence = Article::whereIn('_id', $ids)->get();
        $version = $story->version + 1;
        $event = [
            'event_id' => (string) Str::uuid7(), 'type' => 'story.article_added.v1',
            'aggregate_id' => $story->id, 'aggregate_version' => $version,
            'occurred_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'article_id' => $article->id,
        ];
        $counts = [
            'article_count' => count($ids),
            'source_count' => $evidence->pluck('source_snapshot.uuid')->unique()->count(),
        ];
        if (! $story->exists) {
            // No consumer can see this Story until its first attachment is committed.
            $story->forceFill($counts + [
                'article_ids' => $ids, 'version' => $version, 'events' => [$event],
                'processing' => [
                    'timeline' => ['state' => 'pending', 'projected_version' => 0],
                    'graph' => ['state' => 'pending', 'projected_version' => 0],
                ],
            ])->save();
        } else {
            // Own only attachment fields and pending markers. Never copy consumer progress.
            $result = DB::connection('mongodb')->getMongoDB()->selectCollection('stories')->updateOne(
                ['_id' => $story->id, 'version' => $story->version, 'article_ids' => ['$ne' => $article->id]],
                [
                    '$push' => ['article_ids' => $article->id, 'events' => $event],
                    '$inc' => ['version' => 1],
                    '$set' => $counts + ['processing.timeline.state' => 'pending', 'processing.graph.state' => 'pending'],
                ],
            );
            if ($result->getModifiedCount() !== 1) {
                throw new \RuntimeException('Story changed during attachment; rerun fixture ingest');
            }
        }

        return $event;
    }

    private function missingCanonicalIds(Article $article, array $item): array
    {
        return array_filter([
            'source_id' => $article->source_id === null ? Source::active()->where('feed_url', $item['source']['feed_url'])->value('id') : null,
            'category_id' => $article->category_id === null ? Category::active()->where('slug', $item['category']['slug'])->value('id') : null,
        ], fn ($id) => $id !== null);
    }

    private function indexes(): void
    {
        $db = DB::connection('mongodb')->getMongoDB();
        foreach (['articles' => 'original_url', 'stories' => 'story_key'] as $collection => $key) {
            $db->selectCollection($collection)->createIndex([$key => 1], $collection === 'articles' ? ['unique' => true] : ['unique' => true, 'partialFilterExpression' => [$key => ['$type' => 'string']]]);
        }
    }
}
