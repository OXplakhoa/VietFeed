<?php

namespace Tests\Integration;

use App\Content\ContentCatalog;
use App\Models\Article;
use App\Models\Story;
use App\StoryLens\StoryLens;
use App\StoryProcessing\CassandraClient;
use App\StoryProcessing\Neo4jClient;
use App\StoryProcessing\StoryGraph;
use App\StoryProcessing\StoryPipeline;
use App\StoryProcessing\StoryTimeline;
use Cassandra\Exception\ServerException;
use Cassandra\Value\Timestamp;
use Cassandra\Value\Uuid;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Exception\Neo4jException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\GuardsRedisTestDatabase;
use Tests\TestCase;

class StoryProjectionsTest extends TestCase
{
    use GuardsRedisTestDatabase;

    private array $storyIds = [];

    private array $graphIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['nosql.cassandra.keyspace' => 'vietfeed_test']);
        $this->assertSame('vietfeed_test', config('nosql.cassandra.keyspace'));
        $this->isolateRedis(['nosql']);
        $this->flushTestDatabase(Redis::connection('nosql'));
        app(StoryTimeline::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->graphIds) {
                app(Neo4jClient::class)->run('MATCH (n) WHERE n.uuid IN $ids DETACH DELETE n', ['ids' => $this->graphIds]);
            }
            $this->assertSame('vietfeed_test', config('nosql.cassandra.keyspace'));
            foreach ($this->storyIds as $id) {
                app(CassandraClient::class)->execute('DELETE FROM vietfeed_test.story_events_by_story WHERE story_id = ?', [new Uuid($id)]);
            }
            $this->flushTestDatabase(Redis::connection('nosql'));
        } finally {
            parent::tearDown();
        }
    }

    private function ingest(int $limit = 2): Story
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        $fixture['items'] = array_slice($fixture['items'], 0, $limit);
        $run = (string) Str::uuid7();
        foreach ($fixture['items'] as &$item) {
            $item['source']['feed_url'] .= '?test='.$run;
            $item['category']['slug'] .= '-'.$run;
        }
        unset($item);
        app(ContentCatalog::class)->ingest($fixture);
        foreach (Story::all() as $story) {
            $this->storyIds[] = $story->id;
            $this->graphIds[] = $story->id;
        }
        foreach (Article::all() as $article) {
            array_push($this->graphIds, $article->id, $article->source_snapshot['uuid'], $article->category_snapshot['uuid']);
        }

        return Story::first();
    }

    private function hookProjection(string $section, \Closure $hook): StoryPipeline
    {
        $timeline = new class(app(CassandraClient::class), $hook, $section) extends StoryTimeline
        {
            public function __construct(CassandraClient $client, private \Closure $hook, private string $section)
            {
                parent::__construct($client);
            }

            public function project(Story $story, int $version): void
            {
                if ($this->section === 'timeline') {
                    ($this->hook)($story, $version, fn () => parent::project($story, $version));
                } else {
                    parent::project($story, $version);
                }
            }
        };
        $graph = new class(app(Neo4jClient::class), $hook, $section) extends StoryGraph
        {
            public function __construct(Neo4jClient $client, private \Closure $hook, private string $section)
            {
                parent::__construct($client);
            }

            public function project(Story $story, int $version): void
            {
                if ($this->section === 'graph') {
                    ($this->hook)($story, $version, fn () => parent::project($story, $version));
                } else {
                    parent::project($story, $version);
                }
            }
        };

        return new StoryPipeline($timeline, $graph);
    }

    public static function projectionSections(): array
    {
        return [['timeline'], ['graph']];
    }

    #[DataProvider('projectionSections')]
    public function test_inflight_projection_cannot_mark_a_newer_story_ready(string $section): void
    {
        $story = $this->ingest(1);
        $pipeline = $this->hookProjection($section, function ($snapshot, $version, $project) use ($story) {
            $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
            $fixture['items'] = [$fixture['items'][1]];
            $fixture['items'][0]['source'] = Article::first()->source_snapshot;
            $fixture['items'][0]['category'] = Article::first()->category_snapshot;
            app(ContentCatalog::class)->ingest($fixture);
            foreach (Article::all() as $article) {
                array_push($this->graphIds, $article->id, $article->source_snapshot['uuid'], $article->category_snapshot['uuid']);
            }
            $this->assertSame(2, $story->fresh()->version);
            $project();
        });
        $pipeline->apply($section, $story->events[0]);
        $this->assertSame(['state' => 'pending', 'projected_version' => 1], $story->fresh()->processing[$section]);
        app(StoryPipeline::class)->apply($section, $story->fresh()->events[1]);
        $this->assertSame(['state' => 'ready', 'projected_version' => 2], $story->fresh()->processing[$section]);
    }

    #[DataProvider('projectionSections')]
    public function test_late_success_and_failure_cannot_overwrite_newer_projection_progress(string $section): void
    {
        $story = $this->ingest();
        foreach ([false, true] as $fail) {
            app(StoryPipeline::class)->reemit($story->id);
            $pipeline = $this->hookProjection($section, function ($snapshot, $version, $project) use ($story, $fail, $section) {
                if (! $fail) {
                    $project();
                }
                app(StoryPipeline::class)->apply($section, $story->events[1]);
                if ($fail) {
                    throw new \RuntimeException('late old failure');
                }
            });
            try {
                $pipeline->apply($section, $story->events[0]);
                $this->assertFalse($fail);
            } catch (\RuntimeException $e) {
                $this->assertSame('late old failure', $e->getMessage());
            }
            $this->assertSame(['state' => 'ready', 'projected_version' => 2], $story->fresh()->processing[$section]);
        }
    }

    #[DataProvider('projectionSections')]
    public function test_ingest_does_not_erase_consumer_progress_made_after_its_story_read(string $section): void
    {
        $story = $this->ingest(1);
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        $fixture['items'] = [$fixture['items'][1]];
        $fixture['items'][0]['source'] = Article::first()->source_snapshot;
        $fixture['items'][0]['category'] = Article::first()->category_snapshot;
        $eventName = 'eloquent.saved: '.Article::class;
        Event::listen($eventName, function () use ($story, $section) {
            app(StoryPipeline::class)->apply($section, $story->events[0]);
        });
        try {
            app(ContentCatalog::class)->ingest($fixture);
        } finally {
            Event::forget($eventName);
        }
        $this->assertSame(['state' => 'pending', 'projected_version' => 1], $story->fresh()->processing[$section]);
    }

    #[DataProvider('projectionSections')]
    public function test_missing_canonical_article_fails_each_projection_and_reaches_dlq(string $section): void
    {
        $story = $this->ingest();
        Article::findOrFail($story->article_ids[1])->delete();
        $pipeline = app(StoryPipeline::class);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $pipeline->consume($section, 'missing-evidence', 10);
            foreach ($pipeline->stream($section)->pending() as $pending) {
                Redis::connection('nosql')->client()->xClaim(StoryPipeline::STREAM, 'story-'.$section.'-v1', $pending[1], 0, [$pending[0]], ['IDLE' => 100000, 'JUSTID']);
            }
        }
        $this->assertSame('failed', $story->fresh()->processing[$section]['state']);
        $this->assertSame(1, $pipeline->stream($section)->lengths()['dead_letters']);
        $this->assertSame(1, $story->fresh()->processing[$section]['projected_version']);
    }

    #[DataProvider('projectionSections')]
    public function test_reemit_over_surviving_projection_converges_without_duplicate_facts(string $section): void
    {
        $story = $this->ingest(5);
        $pipeline = app(StoryPipeline::class);
        $pipeline->apply($section, $story->events[4]);
        $before = $section === 'timeline'
            ? app(StoryTimeline::class)->events($story->id)
            : app(StoryGraph::class)->sources($story->id);
        $this->flushTestDatabase(Redis::connection('nosql'));
        $this->assertSame(5, $pipeline->reemit($story->id));
        $this->assertSame(['state' => 'pending', 'projected_version' => 0], $story->fresh()->processing[$section]);
        $this->assertSame(1, $pipeline->consume($section, 'surviving-store', 1));
        $this->assertSame(['state' => 'pending', 'projected_version' => 1], $story->fresh()->processing[$section]);
        $this->assertSame(4, $pipeline->consume($section, 'surviving-store', 10));
        $this->assertSame(['state' => 'ready', 'projected_version' => 5], $story->fresh()->processing[$section]);
        $this->assertSame(0, $pipeline->stream($section)->lengths()['pending']);
        $this->assertSame([], $pipeline->stream($section)->deadLetters());
        $after = $section === 'timeline'
            ? app(StoryTimeline::class)->events($story->id)
            : app(StoryGraph::class)->sources($story->id);
        $this->assertSame($before, $after);
        if ($section === 'graph') {
            $row = app(Neo4jClient::class)->run('MATCH (a:Article)-[r:PART_OF]->(s:Story {uuid: $id}) RETURN s.version AS version, count(r) AS facts', ['id' => $story->id])->first();
            $this->assertSame(5, $row->get('version'));
            $this->assertSame(5, $row->get('facts'));
        } else {
            $this->assertCount(5, $after);
        }
    }

    #[DataProvider('projectionSections')]
    public function test_reemit_repairs_partial_loss_behind_a_surviving_v5_marker(string $section): void
    {
        $story = $this->ingest(5);
        $pipeline = app(StoryPipeline::class);
        foreach (['timeline', 'graph'] as $target) {
            $pipeline->apply($target, $story->events[4]);
        }
        $graph = app(Neo4jClient::class);
        $facts = fn () => $graph->run('MATCH (a:Article)-[:PART_OF]->(s:Story {uuid: $id}), (a)-[:FROM]->(src:Source), (a)-[:IN]->(c:Category) RETURN a.uuid AS article, src.uuid AS source, c.uuid AS category ORDER BY article', ['id' => $story->id])->map(fn ($row) => $row->toArray())->toArray();
        $beforeGraph = $facts();
        $beforeTimeline = app(StoryTimeline::class)->events($story->id);
        $this->assertCount(5, $beforeGraph);
        $this->assertCount(5, $beforeTimeline);
        if ($section === 'graph') {
            $graph->run('MATCH (:Article {uuid: $article})-[r:PART_OF]->(:Story {uuid: $id}) DELETE r', ['article' => $story->article_ids[2], 'id' => $story->id]);
            $this->assertCount(4, $facts());
        } else {
            $event = $story->events[2];
            app(CassandraClient::class)->execute('DELETE FROM vietfeed_test.story_events_by_story WHERE story_id = ? AND occurred_at = ? AND event_id = ?', [new Uuid($story->id), new Timestamp($event['occurred_at']), new Uuid($event['event_id'])])->asVoidResult();
            $this->assertCount(4, app(StoryTimeline::class)->events($story->id));
        }
        $this->assertSame(5, $graph->run('MATCH (s:Story {uuid: $id}) RETURN s.version AS version', ['id' => $story->id])->first()->get('version'));
        $this->flushTestDatabase(Redis::connection('nosql'));
        $this->assertSame(5, $pipeline->reemit($story->id));
        foreach (['timeline', 'graph'] as $target) {
            $this->assertSame(5, $pipeline->consume($target, 'partial-rebuild', 10));
            $this->assertSame(['state' => 'ready', 'projected_version' => 5], $story->fresh()->processing[$target]);
            $this->assertSame(0, $pipeline->stream($target)->lengths()['pending']);
            $this->assertSame([], $pipeline->stream($target)->deadLetters());
        }
        $this->assertSame($beforeGraph, $facts());
        $this->assertSame($beforeTimeline, app(StoryTimeline::class)->events($story->id));
        $this->assertSame(5, $graph->run('MATCH (s:Story {uuid: $id}) RETURN s.version AS version', ['id' => $story->id])->first()->get('version'));
    }

    public function test_reemit_fails_before_any_mongo_reset_or_publish_when_graph_is_down(): void
    {
        $this->ingest(10);
        $pipeline = app(StoryPipeline::class);
        foreach (['timeline', 'graph'] as $section) {
            $pipeline->consume($section, 'before-outage', 100);
        }
        // Gate 9.6 embeds `brief.updated_at` as a Carbon instance; compare the serialized documents, not object identity.
        $snapshot = fn () => json_decode(json_encode(Story::all()->toArray(), JSON_THROW_ON_ERROR), true);
        $before = $snapshot();
        $lengths = $pipeline->stream('graph')->lengths();
        $port = config('nosql.neo4j.bolt_port');
        config(['nosql.neo4j.bolt_port' => 1]);
        try {
            $unavailable = new StoryPipeline(app(StoryTimeline::class), new StoryGraph(new Neo4jClient));
            $unavailable->reemit();
            $this->fail('Rebuild must refuse an unavailable graph before resetting Mongo or publishing');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot rebuild: graph reset failed', $e->getMessage());
        } finally {
            config(['nosql.neo4j.bolt_port' => $port]);
        }
        $this->assertSame($before, $snapshot());
        $this->assertSame($lengths, $pipeline->stream('graph')->lengths());
    }

    #[DataProvider('projectionSections')]
    public function test_older_success_preserves_newer_failure_until_equal_version_recovers(string $section): void
    {
        $story = $this->ingest();
        $failing = $this->hookProjection($section, function () {
            throw new \RuntimeException('injected projection failure');
        });
        try {
            $failing->apply($section, $story->events[1]);
            $this->fail('Version 2 must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected projection failure', $e->getMessage());
        }
        try {
            $failing->apply($section, $story->events[0]);
            $this->fail('Version 1 must fail without replacing the v2 marker');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected projection failure', $e->getMessage());
        }
        $failure = $story->fresh()->processing[$section];
        $this->assertSame(2, $failure['failed_version']);
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        $fixture['items'] = [$fixture['items'][2]];
        $fixture['items'][0]['source'] = Article::first()->source_snapshot;
        $fixture['items'][0]['category'] = Article::first()->category_snapshot;
        app(ContentCatalog::class)->ingest($fixture);
        foreach (Article::all() as $article) {
            array_push($this->graphIds, $article->id, $article->source_snapshot['uuid'], $article->category_snapshot['uuid']);
        }
        $this->assertSame(3, $story->fresh()->version);
        $afterIngest = $story->fresh()->processing[$section];
        $this->assertSame(2, $afterIngest['failed_version']);
        $this->assertSame($failure['error'], $afterIngest['error'] ?? null);
        app(StoryPipeline::class)->apply($section, $story->events[0]);
        $state = $story->fresh()->processing[$section];
        $this->assertSame('failed', $state['state']);
        $this->assertSame(1, $state['projected_version']);
        $this->assertSame(2, $state['failed_version']);
        $this->assertArrayHasKey('error', $state);
        try {
            $failing->apply($section, $story->events[0]);
        } catch (\RuntimeException $e) {
            $this->fail('An already projected version must be ignored');
        }
        $this->assertSame($state, $story->fresh()->processing[$section]);
        app(StoryPipeline::class)->apply($section, $story->events[1]);
        $this->assertSame(['state' => 'pending', 'projected_version' => 2], $story->fresh()->processing[$section]);
        app(StoryPipeline::class)->apply($section, $story->fresh()->events[2]);
        $this->assertSame(['state' => 'ready', 'projected_version' => 3], $story->fresh()->processing[$section]);
    }

    public function test_graph_count_mismatch_does_not_advance_marker_or_cover_retry_on_real_neo4j(): void
    {
        $story = $this->ingest();
        $real = app(Neo4jClient::class);
        $wrongExpectedCount = true;
        $results = [];
        // Forward the actual statement to Neo4j, changing only the expected count.
        // Clean canonical data cannot naturally produce a mismatch; no results are mocked.
        $driver = $this->createMock(ClientInterface::class);
        $driver->method('run')->willReturnCallback(function ($query, $parameters) use ($real, &$wrongExpectedCount, &$results) {
            if ($wrongExpectedCount) {
                $parameters['expected_count'] = 3;
            }
            $result = $real->run($query, $parameters);
            $results[] = $result->first()->toArray();

            return $result;
        });
        $client = new Neo4jClient;
        (new \ReflectionProperty(Neo4jClient::class, 'client'))->setValue($client, $driver);
        $graph = new StoryGraph($client);
        $pipeline = new StoryPipeline(app(StoryTimeline::class), $graph);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $pipeline->apply('graph', $story->events[1]);
                $this->fail('A real count mismatch must fail without advancing the marker');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('projection count', $e->getMessage());
            }
            $this->assertSame(0, $real->run('MATCH (s:Story {uuid: $id}) RETURN coalesce(s.version, 0) AS version', ['id' => $story->id])->first()->get('version'));
            $this->assertFalse($results[$attempt]['covered']);
            $this->assertSame(2, $results[$attempt]['projected']);
            $this->assertSame('failed', $story->fresh()->processing['graph']['state']);
            $this->assertSame(0, $story->fresh()->processing['graph']['projected_version']);
        }
        $wrongExpectedCount = false;
        $this->assertTrue($pipeline->apply('graph', $story->events[1]));
        $this->assertSame(['state' => 'ready', 'projected_version' => 2], $story->fresh()->processing['graph']);
        $graph->project($story, 1);
        $this->assertTrue($results[3]['covered']);
        $this->assertSame(2, $results[3]['version']);
        $this->assertSame(0, $results[3]['projected']);
    }

    public function test_saved_story_publish_interruption_is_pending_and_reemit_repairs_both_sections(): void
    {
        $story = $this->ingest(1);
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        $fixture['items'] = [$fixture['items'][1]];
        $fixture['items'][0]['source'] = Article::first()->source_snapshot;
        $fixture['items'][0]['category'] = Article::first()->category_snapshot;
        $this->flushTestDatabase(Redis::connection('nosql'));
        Redis::connection('nosql')->set(StoryPipeline::STREAM, 'wrong type');
        try {
            app(ContentCatalog::class)->ingest($fixture);
            $this->fail('Redis must reject the publish');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('XADD', $e->getMessage());
        }
        $ids = Article::pluck('id')->all();
        $this->assertCount(2, $ids);
        $this->assertCount(2, $story->fresh()->events);
        foreach (['timeline', 'graph'] as $section) {
            $this->assertSame('pending', app(StoryLens::class)->read($story->id)['sections'][$section]['state']);
        }
        foreach (Article::all() as $article) {
            array_push($this->graphIds, $article->id, $article->source_snapshot['uuid'], $article->category_snapshot['uuid']);
        }
        Redis::connection('nosql')->del(StoryPipeline::STREAM);
        $this->assertSame(0, app(ContentCatalog::class)->ingest($fixture));
        $this->assertSame(2, app(StoryPipeline::class)->reemit($story->id));
        foreach (['timeline', 'graph'] as $section) {
            $this->assertSame(2, app(StoryPipeline::class)->consume($section, 'repair-publish', 10));
            $this->assertSame('ready', app(StoryLens::class)->read($story->id)['sections'][$section]['state']);
        }
        $this->assertSame($ids, Article::pluck('id')->all());
        $this->assertSame(1, Story::count());
    }

    public function test_timeline_projects_history_idempotently_and_ignores_stale_versions(): void
    {
        $story = $this->ingest();
        $pipeline = app(StoryPipeline::class);
        $this->assertTrue($pipeline->apply('timeline', $story->events[1]));
        $rows = app(StoryTimeline::class)->events($story->id);
        $this->assertCount(2, $rows);
        $this->assertSame($story->events[1]['event_id'], $rows[0]['event_id']);
        $this->assertFalse($pipeline->apply('timeline', $story->events[1]));
        $this->assertFalse($pipeline->apply('timeline', $story->events[0]));
        $this->assertSame($rows, app(StoryTimeline::class)->events($story->id));
        $this->assertSame('ready', app(StoryLens::class)->read($story->id)['sections']['timeline']['state']);
    }

    public function test_graph_merges_canonical_topology_and_ignores_duplicate_and_stale_versions(): void
    {
        $story = $this->ingest(5);
        $pipeline = app(StoryPipeline::class);
        $this->assertTrue($pipeline->apply('graph', $story->events[4]));
        $sources = app(StoryGraph::class)->sources($story->id);
        $this->assertCount(2, $sources);
        $this->assertSame(5, array_sum(array_column($sources, 'article_count')));
        $this->assertFalse($pipeline->apply('graph', $story->events[4]));
        $this->assertFalse($pipeline->apply('graph', $story->events[0]));
        $this->assertSame($sources, app(StoryGraph::class)->sources($story->id));
        $row = app(Neo4jClient::class)->run('MATCH (a:Article)-[:PART_OF]->(s:Story {uuid: $id}), (a)-[:IN]->(c:Category) RETURN count(a) AS articles, count(DISTINCT c) AS categories', ['id' => $story->id])->first();
        $this->assertSame(5, $row->get('articles'));
        $this->assertSame(1, $row->get('categories'));
        $this->assertSame(0, app(Neo4jClient::class)->run('MATCH (a:Article)-[:PART_OF]->(:Story {uuid: $id}) WHERE a.body IS NOT NULL OR a.description IS NOT NULL RETURN count(a) AS bodies', ['id' => $story->id])->first()->get('bodies'));
    }

    public function test_applied_but_unacked_deliveries_recover_independently_for_both_projections(): void
    {
        $story = $this->ingest();
        $pipeline = app(StoryPipeline::class);
        foreach (['timeline', 'graph'] as $section) {
            $stream = $pipeline->stream($section);
            $delivery = $stream->receive('interrupted');
            $this->assertNotNull($delivery);
            $pipeline->apply($section, json_decode($delivery['event'], true));
            Redis::connection('nosql')->client()->xClaim(StoryPipeline::STREAM, 'story-'.$section.'-v1', 'interrupted', 0, [$delivery['id']], ['IDLE' => 100000, 'JUSTID']);
            $this->assertSame(2, $pipeline->consume($section, 'recovery', 10));
            $this->assertSame(0, $stream->lengths()['pending']);
        }
        $this->assertCount(2, app(StoryTimeline::class)->events($story->id));
        $this->assertSame(2, app(StoryGraph::class)->sources($story->id)[0]['article_count']);
        $read = app(StoryLens::class)->read($story->id);
        $this->assertSame('ready', $read['sections']['timeline']['state']);
        $this->assertSame('ready', $read['sections']['graph']['state']);
    }

    public function test_graph_outage_is_visible_and_dlq_replay_recovers_without_reingesting(): void
    {
        $story = $this->ingest(1);
        $ids = Article::pluck('id')->all();
        $storyIds = Story::pluck('id')->all();
        $pipeline = app(StoryPipeline::class);
        $pipeline->consume('timeline', 'timeline', 10);
        // A real connection failure at the external database boundary, not a fake handler.
        $port = config('nosql.neo4j.bolt_port');
        config(['nosql.neo4j.bolt_port' => 1]);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->artisan('storylens consume --section=graph --limit=1')->assertExitCode(0);
            $pending = $pipeline->stream('graph')->pending();
            if ($pending) {
                Redis::connection('nosql')->client()->xClaim(StoryPipeline::STREAM, 'story-graph-v1', $pending[0][1], 0, [$pending[0][0]], ['IDLE' => 100000, 'JUSTID']);
            }
        }
        $stream = $pipeline->stream('graph');
        $this->assertSame(1, $stream->lengths()['dead_letters']);
        $this->assertSame(5, (int) array_values($stream->deadLetters())[0]['attempts']);
        $read = app(StoryLens::class)->read($story->id);
        $this->assertCount(1, $read['evidence']);
        $this->assertSame('ready', $read['sections']['timeline']['state']);
        $this->assertSame('failed', $read['sections']['graph']['state']);
        $this->assertSame(0, Artisan::call('storylens', ['action' => 'inspect', '--section' => 'graph', '--story' => $story->id]));
        $inspection = Artisan::output();
        $this->assertStringContainsString('dead_letters', $inspection);
        $this->assertStringContainsString('failed', $inspection);
        config(['nosql.neo4j.bolt_port' => $port]);
        $deadId = array_key_first($stream->deadLetters());
        $this->artisan('storylens replay --section=graph --id='.$deadId)->expectsOutputToContain('Requeued')->assertExitCode(0);
        $this->artisan('storylens consume --section=graph')->expectsOutputToContain('Processed 1')->assertExitCode(0);
        $this->assertSame('ready', app(StoryLens::class)->read($story->id)['sections']['graph']['state']);
        $this->assertSame($ids, Article::pluck('id')->all());
        $this->assertSame($storyIds, Story::pluck('id')->all());
        $this->assertSame(0, $stream->lengths()['dead_letters']);
    }

    public function test_full_fixture_converges_and_mongo_rebuilds_lost_dispatch_and_projections(): void
    {
        $this->ingest(10);
        $pipeline = app(StoryPipeline::class);
        $ids = Article::pluck('id')->all();
        $storyIds = Story::pluck('id')->all();
        $started = microtime(true);
        foreach (['timeline', 'graph'] as $section) {
            $this->assertSame(10, $pipeline->consume($section, 'converge', 100));
        }
        foreach ($storyIds as $id) {
            $read = app(StoryLens::class)->read($id);
            $this->assertSame('ready', $read['sections']['timeline']['state']);
            $this->assertSame('ready', $read['sections']['graph']['state']);
            $this->assertSame(5, $read['sections']['timeline']['projected_version']);
            $this->assertCount(5, $read['sections']['timeline']['data']);
            $this->assertCount(2, $read['sections']['graph']['data']);
            $this->assertCount(5, app(StoryTimeline::class)->events($id));
            $this->assertCount(2, app(StoryGraph::class)->sources($id));
        }
        fwrite(STDERR, sprintf("StoryLens local fixture convergence: %.3f seconds\n", microtime(true) - $started));
        $this->flushTestDatabase(Redis::connection('nosql'));
        app(Neo4jClient::class)->run('MATCH (n) WHERE n.uuid IN $ids DETACH DELETE n', ['ids' => $this->graphIds]);
        $this->assertSame('vietfeed_test', config('nosql.cassandra.keyspace'));
        foreach ($storyIds as $id) {
            app(CassandraClient::class)->execute('DELETE FROM vietfeed_test.story_events_by_story WHERE story_id = ?', [new Uuid($id)])->asVoidResult();
        }
        $this->artisan('storylens reemit')->expectsOutputToContain('Re-emitted 10')->assertExitCode(0);
        foreach ($storyIds as $id) {
            $this->assertSame('pending', app(StoryLens::class)->read($id)['sections']['graph']['state']);
        }
        foreach (['timeline', 'graph'] as $section) {
            $this->assertSame(10, $pipeline->consume($section, 'rebuild', 100));
        }
        foreach ($storyIds as $id) {
            $read = app(StoryLens::class)->read($id);
            $this->assertSame('ready', $read['sections']['timeline']['state']);
            $this->assertSame('ready', $read['sections']['graph']['state']);
            $this->assertCount(5, app(StoryTimeline::class)->events($id));
            $this->assertCount(2, app(StoryGraph::class)->sources($id));
        }
        $this->assertSame($ids, Article::pluck('id')->all());
        $this->assertSame($storyIds, Story::pluck('id')->all());
    }

    public function test_clients_surface_query_errors_and_repeated_bootstrap_is_safe(): void
    {
        app(StoryTimeline::class)->bootstrap();
        $this->assertSame('vietfeed_test', app(CassandraClient::class)->keyspace());
        $this->assertCount(1, app(CassandraClient::class)->rows("SELECT keyspace_name FROM system_schema.keyspaces WHERE keyspace_name = 'vietfeed_test'"));
        foreach (['cassandra', 'neo4j'] as $store) {
            try {
                $store === 'cassandra'
                    ? app(CassandraClient::class)->execute('INVALID CQL')
                    : app(Neo4jClient::class)->run('INVALID CYPHER');
                $this->fail('Invalid query must surface an exception');
            } catch (\RuntimeException $e) {
                $this->assertNotNull($e->getPrevious());
                $this->assertInstanceOf($store === 'cassandra' ? ServerException::class : Neo4jException::class, $e->getPrevious());
                $this->assertStringContainsString('request failed', $e->getMessage());
            }
        }
    }

    public function test_cassandra_refuses_dev_keyspace_in_test_environment(): void
    {
        config(['nosql.cassandra.keyspace' => 'vietfeed']);
        try {
            app(StoryTimeline::class)->bootstrap();
            $this->fail('Test must refuse dev keyspace before schema writes');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing Cassandra', $e->getMessage());
        } finally {
            config(['nosql.cassandra.keyspace' => 'vietfeed_test']);
        }
    }
}
