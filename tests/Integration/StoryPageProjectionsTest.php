<?php

namespace Tests\Integration;

use App\Content\ContentCatalog;
use App\Models\Article;
use App\Models\Story;
use App\Models\User;
use App\StoryProcessing\CassandraClient;
use App\StoryProcessing\Neo4jClient;
use App\StoryProcessing\StoryPipeline;
use App\StoryProcessing\StoryTimeline;
use Cassandra\Value\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Concerns\GuardsRedisTestDatabase;
use Tests\TestCase;

class StoryPageProjectionsTest extends TestCase
{
    use GuardsRedisTestDatabase, RefreshDatabase;

    private array $storyIds = [];

    private array $graphIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['nosql.cassandra.keyspace' => 'vietfeed_test', 'nosql.story_retry_ms' => 1]);
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

    private function ingest(): Story
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        $fixture['items'] = [$fixture['items'][0]];
        $run = (string) Str::uuid7();
        $fixture['items'][0]['source']['feed_url'] .= '?http-test='.$run;
        $fixture['items'][0]['category']['slug'] .= '-'.$run;
        app(ContentCatalog::class)->ingest($fixture);
        $story = Story::firstOrFail();
        $this->storyIds[] = $story->id;
        $article = Article::firstOrFail();
        $this->graphIds = [$story->id, $article->id, $article->source_snapshot['uuid'], $article->category_snapshot['uuid']];

        return $story;
    }

    public function test_ready_sections_render_real_data_and_each_read_outage_preserves_http_evidence(): void
    {
        $story = $this->ingest();
        $pipeline = app(StoryPipeline::class);
        foreach (['timeline', 'graph'] as $section) {
            $pipeline->consume($section, 'http-ready', 10);
        }
        $article = Article::firstOrFail();
        $rawTime = app(StoryTimeline::class)->events($story->id)[0]['occurred_at'];
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\+0000$/', $rawTime);
        $isoTime = substr($rawTime, 0, 10).'T'.substr($rawTime, 11, 8).'+00:00';
        $this->get('/stories/'.$story->id)->assertOk()->assertSee('datetime="'.$isoTime.'"', false)->assertSee('data-state="ready"', false)
            ->assertSee($article->source_snapshot['name'])->assertSee('Bổ sung bài viết · '.$article->title)->assertDontSee($article->id);
        $this->getJson('/stories/'.$story->id.'/sections')->assertOk()
            ->assertJsonPath('sections.timeline.state', 'ready')->assertJsonCount(1, 'sections.timeline.data')
            ->assertJsonPath('sections.timeline.data.0.event_id', $story->events[0]['event_id'])
            ->assertJsonPath('sections.timeline.data.0.article_title', $article->title)
            ->assertJsonPath('sections.timeline.data.0.occurred_at', $isoTime)
            ->assertJsonStructure(['evidence' => [['published_at_display']], 'sections' => ['timeline' => ['data' => [['occurred_at_display']]]]])
            ->assertJsonPath('sections.graph.state', 'ready')->assertJsonCount(1, 'sections.graph.data')
            ->assertJsonPath('sections.graph.data.0.article_count', 1);
        $ports = ['nosql.cassandra.port' => config('nosql.cassandra.port'), 'nosql.neo4j.bolt_port' => config('nosql.neo4j.bolt_port')];
        try {
            foreach (['timeline', 'graph', 'both'] as $fault) {
                config($ports);
                if ($fault !== 'graph') {
                    config(['nosql.cassandra.port' => 1]);
                }
                if ($fault !== 'timeline') {
                    config(['nosql.neo4j.bolt_port' => 1]);
                }
                $this->get('/stories/'.$story->id)->assertOk()->assertSee($article->title)
                    ->assertSee('Tạm thời chưa thể tải mục này.')->assertDontSee('request failed')->assertDontSee('127.0.0.1');
                $json = $this->getJson('/stories/'.$story->id.'/sections')->assertOk()->assertJsonCount(1, 'evidence');
                foreach (['timeline', 'graph'] as $section) {
                    $json->assertJsonPath('sections.'.$section.'.state', $fault === 'both' || $fault === $section ? 'failed' : 'ready')
                        ->assertJsonMissingPath('sections.'.$section.'.error');
                }
            }
        } finally {
            config($ports);
        }
    }

    public function test_graph_dlq_admin_retry_and_consumers_recover_over_http_without_reingesting(): void
    {
        $story = $this->ingest();
        $articleIds = Article::pluck('id')->all();
        $storyIds = Story::pluck('id')->all();
        $pipeline = app(StoryPipeline::class);
        $pipeline->consume('timeline', 'http-timeline', 10);
        $port = config('nosql.neo4j.bolt_port');
        try {
            config(['nosql.neo4j.bolt_port' => 1]);
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $this->artisan('storylens consume --section=graph --limit=1')->assertExitCode(0);
                foreach ($pipeline->stream('graph')->pending() as $pending) {
                    Redis::connection('nosql')->client()->xClaim(StoryPipeline::STREAM, 'story-graph-v1', $pending[1], 0, [$pending[0]], ['IDLE' => 100000, 'JUSTID']);
                }
            }
            $this->get('/stories/'.$story->id)->assertOk()->assertSee('data-state="failed"', false)->assertSee($story->title);
            $this->getJson('/stories/'.$story->id.'/sections')->assertOk()
                ->assertJsonPath('sections.timeline.state', 'ready')->assertJsonPath('sections.graph.state', 'failed');
            $dead = array_key_first($pipeline->stream('graph')->deadLetters());
            $this->assertNotNull($dead);
            $this->actingAs(User::factory()->create(['role' => 'admin']));
            $this->get('/admin/processing')->assertOk()->assertSee($dead)->assertSee('failed')->assertSee($story->id);
            $this->post('/admin/processing/retry', ['section' => 'graph', 'id' => $dead])->assertRedirect('/admin/processing')->assertSessionHas('success');
            // Requeue has not run a projection, and repeat submission has no effect.
            $this->getJson('/stories/'.$story->id.'/sections')->assertJsonPath('sections.graph.state', 'failed');
            $this->post('/admin/processing/retry', ['section' => 'graph', 'id' => $dead])->assertSessionHas('error');
        } finally {
            config(['nosql.neo4j.bolt_port' => $port]);
        }
        $this->artisan('storylens consume --section=graph')->assertExitCode(0);
        $this->artisan('storylens consume --section=timeline')->assertExitCode(0);
        $this->get('/stories/'.$story->id)->assertOk()->assertDontSee('data-state="failed"', false)->assertDontSee('data-state="pending"', false);
        $this->getJson('/stories/'.$story->id.'/sections')->assertOk()
            ->assertJsonPath('sections.timeline.state', 'ready')->assertJsonPath('sections.graph.state', 'ready')
            ->assertJsonCount(1, 'sections.timeline.data')->assertJsonPath('sections.graph.data.0.article_count', 1);
        $this->assertSame([], $pipeline->stream('graph')->deadLetters());
        $this->assertSame($articleIds, Article::pluck('id')->all());
        $this->assertSame($storyIds, Story::pluck('id')->all());
        $this->assertSame(1, Article::count());
        $this->assertSame(1, Story::count());
    }
}
