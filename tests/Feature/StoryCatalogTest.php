<?php

namespace Tests\Feature;

use App\Content\ContentCatalog;
use App\Models\Article;
use App\Models\Category;
use App\Models\Source;
use App\Models\Story;
use App\StoryLens\StoryLens;
use App\StoryProcessing\StoryPipeline;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;
use Tests\Concerns\GuardsRedisTestDatabase;
use Tests\TestCase;

class StoryCatalogTest extends TestCase
{
    use GuardsRedisTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateRedis(['nosql']);
        $this->flushTestDatabase(Redis::connection('nosql'));
    }

    protected function tearDown(): void
    {
        $this->flushTestDatabase(Redis::connection('nosql'));
        parent::tearDown();
    }

    public function test_gate3_story_remains_readable_and_pipeline_operations_are_safe(): void
    {
        $story = Story::create(['title' => 'Gate 3 Story', 'status' => 'active']);
        $read = app(StoryLens::class)->read($story->id);
        $this->assertSame([], $read['evidence']);
        foreach (['timeline', 'graph'] as $section) {
            $this->assertSame(['state' => 'pending', 'projected_version' => 0, 'data' => []], $read['sections'][$section]);
        }
        $pipeline = app(StoryPipeline::class);
        $inspection = $pipeline->inspect('graph', $story->id);
        $this->assertSame(0, $inspection['stories'][0]['version']);
        $this->assertSame('pending', $inspection['stories'][0]['processing']['graph']['state']);
        $this->assertSame(0, $pipeline->reemit($story->id));
        try {
            $pipeline->apply('graph', ['aggregate_id' => $story->id, 'event_id' => 'unknown']);
            $this->fail('No canonical event means no projection');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('canonical Story history', $e->getMessage());
        }
    }

    public function test_article_to_story_interruption_recovers_identity_and_exactly_one_attachment_event(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        $fixture['items'] = [$fixture['items'][0]];
        $eventName = 'eloquent.saving: '.Story::class;
        Event::listen($eventName, fn () => throw new \RuntimeException('Story write interrupted'));
        try {
            app(ContentCatalog::class)->ingest($fixture);
            $this->fail('Story write must be interrupted after Article persistence');
        } catch (\RuntimeException $e) {
            $this->assertSame('Story write interrupted', $e->getMessage());
        } finally {
            Event::forget($eventName);
        }
        $article = Article::firstOrFail();
        $this->assertNull($article->source_id);
        $this->assertNull($article->category_id);
        $this->assertSame(0, Story::count());
        $this->assertSame(0, app(StoryPipeline::class)->stream('graph')->lengths()['stream']);
        $this->assertSame(1, app(ContentCatalog::class)->ingest($fixture));
        $this->assertSame(0, app(ContentCatalog::class)->ingest($fixture));
        $story = Story::firstOrFail();
        $this->assertSame($article->story_id, $story->id);
        $this->assertSame([$article->id], $story->article_ids);
        $this->assertCount(1, $story->events);
        $this->assertSame($article->id, $story->events[0]['article_id']);
        $this->assertSame(1, Article::count());
        $this->assertSame(1, app(StoryPipeline::class)->stream('graph')->lengths()['stream']);
    }

    public function test_frozen_evidence_is_available_before_projection_and_reingest_does_not_reattach(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        $category = Category::create($fixture['items'][0]['category']);
        $source = Source::create($fixture['items'][0]['source'] + ['category_id' => $category->id]);
        $otherSource = Source::create($fixture['items'][3]['source'] + ['category_id' => $category->id, 'is_active' => false]);
        $catalog = app(ContentCatalog::class);
        $this->assertSame(10, $catalog->ingest($fixture));
        $this->assertSame(10, Article::count());
        $this->assertSame(2, Story::count());
        $ids = Article::pluck('id')->all();
        $linked = Article::where('original_url', $fixture['items'][0]['link'])->sole();
        $unlinked = Article::where('original_url', $fixture['items'][3]['link'])->sole();
        $this->assertSame($source->id, $linked->source_id);
        $this->assertSame($category->id, $linked->category_id);
        $this->assertNull($unlinked->source_id);
        $this->get('/articles/'.$linked->slug)->assertOk()->assertSee($linked->title);
        $this->get('/articles/'.$unlinked->slug)->assertNotFound();
        foreach (Story::all() as $story) {
            $read = app(StoryLens::class)->read($story->id);
            $this->assertCount(5, $read['evidence']);
            $this->assertSame(5, $read['story']['article_count']);
            $this->assertSame(2, $read['story']['source_count']);
            $this->assertSame('pending', $read['sections']['timeline']['state']);
            $this->assertSame('pending', $read['sections']['graph']['state']);
            foreach ($read['evidence'] as $article) {
                $this->assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-7[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $article['id']);
                $this->assertNotEmpty($article['source']['name']);
                $this->assertLessThanOrEqual(200, mb_strlen($article['excerpt']));
            }
        }
        $otherSource->update(['is_active' => true]);
        $this->assertSame(0, $catalog->ingest($fixture));
        $this->assertSame($otherSource->id, $unlinked->fresh()->source_id);
        $this->get('/articles/'.$unlinked->slug)->assertOk()->assertSee($unlinked->title);
        $this->assertSame($ids, Article::pluck('id')->all());
        $this->assertSame(10, app(StoryPipeline::class)->stream('graph')->lengths()['stream']);
    }

    public function test_invalid_fixture_is_rejected_before_any_content_or_event_is_written(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        $fixture['items'][9]['excerpt'] = str_repeat('a', 201);
        try {
            app(ContentCatalog::class)->ingest($fixture);
            $this->fail('Invalid fixture must be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.9.excerpt', $e->errors());
        }
        $this->assertSame(0, Article::count());
        $this->assertSame(0, Story::count());
        $this->assertSame(0, app(StoryPipeline::class)->stream('graph')->lengths()['stream']);
    }

    public function test_canonical_history_retains_versioned_uuid7_events_and_unique_url_identity(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        app(ContentCatalog::class)->ingest($fixture);
        foreach (Story::all() as $story) {
            $this->assertSame([1, 2, 3, 4, 5], array_column($story->events, 'aggregate_version'));
            foreach ($story->events as $event) {
                $this->assertSame($story->id, $event['aggregate_id']);
                $this->assertSame('story.article_added.v1', $event['type']);
                $this->assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-7[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $event['event_id']);
                $this->assertMatchesRegularExpression('/T.*Z$/', $event['occurred_at']);
            }
        }
        $original = Article::first();
        try {
            (new Article)->forceFill(['original_url' => $original->original_url, 'title' => 'duplicate'])->save();
            $this->fail('Mongo must enforce unique URL identity');
        } catch (BulkWriteException $e) {
            $this->assertSame(11000, $e->getCode());
        }
        $this->assertSame(10, Article::count());
    }

    public function test_publish_failure_retains_mongo_facts_for_explicit_reemit(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        $fixture['items'] = [$fixture['items'][0]];
        Redis::connection('nosql')->set(StoryPipeline::STREAM, 'wrong type');
        try {
            app(ContentCatalog::class)->ingest($fixture);
            $this->fail('Rejected Redis publish must be visible');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('XADD', $e->getMessage());
            $this->assertStringContainsString('WRONGTYPE', $e->getMessage());
        }
        $story = Story::firstOrFail();
        $ids = Article::pluck('id')->all();
        $this->assertCount(1, app(StoryLens::class)->read($story->id)['evidence']);
        $this->assertSame('pending', $story->processing['graph']['state']);
        Redis::connection('nosql')->del(StoryPipeline::STREAM);
        $this->assertSame(0, app(ContentCatalog::class)->ingest($fixture));
        $this->assertSame(1, app(StoryPipeline::class)->reemit($story->id));
        $stream = app(StoryPipeline::class)->stream('graph');
        $delivery = $stream->receive('verify');
        $this->assertSame($story->events[0], json_decode($delivery['event'], true));
        $this->assertSame($ids, Article::pluck('id')->all());
    }

    public function test_forged_projection_event_cannot_advance_processing_state(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        $fixture['items'] = [$fixture['items'][0]];
        app(ContentCatalog::class)->ingest($fixture);
        $story = Story::firstOrFail();
        $event = $story->events[0];
        $event['aggregate_version'] = 500;
        try {
            app(StoryPipeline::class)->apply('graph', $event);
            $this->fail('Events must match canonical history');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('canonical Story history', $e->getMessage());
        }
        $this->assertSame(['state' => 'pending', 'projected_version' => 0], $story->fresh()->processing['graph']);
    }

    public function test_existing_rss_article_is_adopted_without_duplicating_its_url_or_identity(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        $fixture['items'] = [$fixture['items'][0]];
        $article = Article::create(['title' => $fixture['items'][0]['title'], 'original_url' => $fixture['items'][0]['link'], 'description' => 'Previously fetched', 'published_at' => $fixture['items'][0]['published_at']]);
        $this->assertSame(1, app(ContentCatalog::class)->ingest($fixture));
        $this->assertSame(1, Article::count());
        $read = app(StoryLens::class)->read(Story::firstOrFail()->id);
        $this->assertSame($article->id, $read['evidence'][0]['id']);
        $this->assertSame('VnExpress – Thế giới', $read['evidence'][0]['source']['name']);
        $this->assertSame(Story::first()->id, $article->fresh()->story_id);
    }
}
