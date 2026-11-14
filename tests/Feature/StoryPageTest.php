<?php

namespace Tests\Feature;

use App\Content\ContentCatalog;
use App\Models\Article;
use App\Models\Story;
use App\Models\User;
use App\StoryProcessing\StoryPipeline;
use App\StoryProcessing\StoryTimeline;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use MongoDB\Driver\Exception\ConnectionException;
use Tests\Concerns\GuardsRedisTestDatabase;
use Tests\TestCase;

class StoryPageTest extends TestCase
{
    use GuardsRedisTestDatabase, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nosql.cassandra.keyspace' => 'vietfeed_test', 'nosql.story_retry_ms' => 1]);
        $this->isolateRedis(['nosql']);
        $this->flushTestDatabase(Redis::connection('nosql'));
    }

    protected function tearDown(): void
    {
        $this->flushTestDatabase(Redis::connection('nosql'));
        parent::tearDown();
    }

    private function ingest(): Story
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        $fixture['items'] = array_slice($fixture['items'], 0, 2);
        app(ContentCatalog::class)->ingest($fixture);

        return Story::firstOrFail();
    }

    private function deadLetter(): string
    {
        $stream = app(StoryPipeline::class)->stream('graph');
        // Exercise the actual consumer's recorded envelope, without either projection store.
        for ($i = 0; $i < 5; $i++) {
            $stream->consume('http-test', 1, fn () => throw new \RuntimeException('SECRET bolt://private-host MATCH (n) password'));
            foreach ($stream->pending() as $pending) {
                Redis::connection('nosql')->client()->xClaim(StoryPipeline::STREAM, 'story-graph-v1', $pending[1], 0, [$pending[0]], ['IDLE' => 100000, 'JUSTID']);
            }
        }

        return array_key_first($stream->deadLetters());
    }

    public function test_story_first_response_contains_all_evidence_before_pending_sections_and_json_is_safe(): void
    {
        $story = $this->ingest();
        $response = $this->get('/stories/'.$story->id)->assertOk()->assertSee($story->title);
        foreach (Article::all() as $article) {
            $response->assertSee($article->title)->assertSee($article->original_url)->assertSee($article->source_snapshot['name'])
                ->assertSee($article->description)->assertSee('datetime="'.$article->published_at->toIso8601String().'"', false)
                ->assertSee($article->published_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i'));
        }
        $response->assertSeeInOrder(['id="story-evidence"', 'data-section="timeline"', 'data-section="graph"'], false)
            ->assertSee('data-state="pending"', false);
        $this->getJson('/stories/'.$story->id.'/sections')->assertOk()->assertJsonPath('story.id', $story->id)
            ->assertJsonPath('sections.timeline.state', 'pending')->assertJsonPath('sections.graph.data', [])
            ->assertJsonPath('sections.graph.projected_version', 0)->assertJsonMissingPath('sections.graph.error');
    }

    public function test_legacy_unsafe_article_urls_are_plain_text_in_html_and_null_in_json(): void
    {
        $story = $this->ingest();
        $article = Article::firstOrFail();
        foreach (['javascript:alert(1)', 'data:text/html,unsafe', '//example.com/news', 'https://', 'https://example.com/bad path'] as $url) {
            $article->forceFill(['original_url' => $url])->save();
            $this->get('/stories/'.$story->id)->assertOk()->assertSee($article->title)
                ->assertDontSee('href="'.$url.'"', false);
            $json = $this->getJson('/stories/'.$story->id.'/sections')->assertOk();
            $row = collect($json->json('evidence'))->firstWhere('id', $article->id);
            $this->assertNull($row['original_url']);
        }
        foreach (['http://example.com/news', 'https://example.com/news'] as $url) {
            $article->forceFill(['original_url' => $url])->save();
            $this->get('/stories/'.$story->id)->assertOk()->assertSee('href="'.$url.'"', false);
        }
    }

    public function test_malformed_and_unknown_story_ids_are_404_for_both_routes(): void
    {
        foreach (['not-a-uuid', (string) Str::uuid7()] as $id) {
            $this->get('/stories/'.$id)->assertNotFound();
            $this->getJson('/stories/'.$id.'/sections')->assertNotFound();
        }
    }

    public function test_legacy_story_without_tracer_fields_renders(): void
    {
        $story = Story::create(['title' => 'Sự kiện cũ', 'status' => 'active']);
        $this->get('/stories/'.$story->id)->assertOk()->assertSee('Sự kiện cũ')->assertSee('Chưa có bài viết');
        $this->getJson('/stories/'.$story->id.'/sections')->assertOk()->assertJsonPath('story.version', 0)
            ->assertJsonPath('sections.graph.state', 'pending');
    }

    public function test_failed_sections_never_expose_stored_error_in_html_or_json(): void
    {
        $story = $this->ingest();
        $story->forceFill(['processing' => [
            'graph' => ['state' => 'failed', 'projected_version' => 0, 'error' => 'SECRET bolt://private-host MATCH (n) password'],
        ]])->save();
        $this->get('/stories/'.$story->id)->assertOk()->assertSee('Tạm thời chưa thể tải mục này.')
            ->assertSee('data-state="failed"', false)->assertDontSee('SECRET')->assertDontSee('private-host');
        $this->getJson('/stories/'.$story->id.'/sections')->assertOk()->assertJsonPath('sections.graph.state', 'failed')
            ->assertJsonMissingPath('sections.graph.error')->assertDontSee('SECRET');
    }

    public function test_processing_routes_require_admin(): void
    {
        $this->get('/admin/processing')->assertRedirect('/login');
        $this->post('/admin/processing/retry')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'user']));
        $this->get('/admin/processing')->assertForbidden();
        $this->post('/admin/processing/retry')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/admin/processing')->assertOk()->assertSee('Xử lý Story');
    }

    public function test_admin_sees_actual_pending_and_dlq_metadata_and_retry_only_requeues(): void
    {
        $story = $this->ingest();
        $stream = app(StoryPipeline::class)->stream('graph');
        $dead = $this->deadLetter();
        $pending = $stream->receive('waiting-worker');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/admin/processing')->assertOk()->assertSee($dead)->assertSee($story->title.' · '.$story->id)
            ->assertSee($pending['id'])->assertSee('waiting-worker')->assertSee('story.article_added.v1')
            ->assertSee('Lỗi xử lý đã được ghi nhận.')->assertDontSee('SECRET')->assertDontSee('private-host')
            ->assertSee('name="_token"', false)->assertSee('/stories/'.$story->id, false);
        $ids = Article::pluck('id')->all();
        $length = $stream->lengths()['stream'];
        $this->post('/admin/processing/retry', ['section' => 'graph', 'id' => $dead])->assertRedirect('/admin/processing')->assertSessionHas('success');
        $this->assertSame($length + 1, $stream->lengths()['stream']);
        $this->assertSame([], $stream->deadLetters());
        $this->assertSame($ids, Article::pluck('id')->all());
        $this->assertSame('pending', $story->fresh()->processing['graph']['state']);
        $this->post('/admin/processing/retry', ['section' => 'graph', 'id' => $dead])->assertRedirect('/admin/processing')->assertSessionHas('error');
        $this->assertSame($length + 1, $stream->lengths()['stream']);
    }

    public function test_timeline_uses_canonical_titles_and_vietnamese_display_times_in_html_and_json(): void
    {
        $story = $this->ingest();
        $article = Article::firstOrFail();
        $article->forceFill(['published_at' => '2026-11-14T02:51:55Z'])->save();
        $story->forceFill(['processing' => ['timeline' => ['state' => 'ready', 'projected_version' => $story->version]]])->save();
        $rows = [];
        foreach ([$article->id, (string) Str::uuid7()] as $id) {
            $rows[] = ['story_id' => $story->id, 'event_id' => (string) Str::uuid7(), 'aggregate_version' => 1,
                'type' => 'story.article_added.v1', 'occurred_at' => '2026-11-14T03:30:00+00:00', 'article_id' => $id];
        }
        $this->mock(StoryTimeline::class)->shouldReceive('events')->andReturn($rows);
        $this->get('/stories/'.$story->id)->assertOk()
            ->assertSee('14/11/2026 09:51')->assertSee('datetime="2026-11-14T09:51:55+07:00"', false)
            ->assertSee('14/11/2026 10:30')->assertSee('datetime="2026-11-14T03:30:00+00:00"', false)
            ->assertSee('Bổ sung bài viết · '.$article->title)->assertSee('Bài viết chưa có trong danh sách nguồn')
            ->assertDontSee($article->id)->assertDontSee($rows[1]['article_id']);
        $this->getJson('/stories/'.$story->id.'/sections')->assertOk()
            ->assertJsonPath('sections.timeline.data.0.article_title', $article->title)
            ->assertJsonPath('sections.timeline.data.0.occurred_at_display', '14/11/2026 10:30')
            ->assertJsonPath('sections.timeline.data.1.article_title', 'Bài viết chưa có trong danh sách nguồn');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/admin/processing')->assertOk()->assertSee($story->title.' · '.$story->id);
    }

    public function test_malformed_timeline_rows_fail_only_that_section_and_preserve_evidence(): void
    {
        $story = $this->ingest();
        $article = Article::firstOrFail();
        $story->forceFill(['processing' => ['timeline' => ['state' => 'ready', 'projected_version' => $story->version]]])->save();
        $valid = ['story_id' => $story->id, 'event_id' => (string) Str::uuid7(), 'aggregate_version' => 1,
            'type' => 'story.article_added.v1', 'occurred_at' => '2026-11-14 23:31:11.161+0000', 'article_id' => $article->id];
        foreach ([['occurred_at' => 'SECRET private-host invalid timestamp'], ['article_id' => ['SECRET']], ['article_id' => (object) ['secret' => 'private-host']], ['occurred_at' => ['SECRET']]] as $malformed) {
            $this->mock(StoryTimeline::class)->shouldReceive('events')->andReturn([$valid, array_replace($valid, $malformed)]);
            $this->get('/stories/'.$story->id)->assertOk()->assertSee($article->title)
                ->assertSee('data-section="timeline" data-state="failed"', false)
                ->assertSee('Tạm thời chưa thể tải mục này.')->assertDontSee('SECRET')->assertDontSee('private-host');
            $this->getJson('/stories/'.$story->id.'/sections')->assertOk()->assertJsonCount(2, 'evidence')
                ->assertJsonPath('sections.timeline.state', 'failed')->assertJsonPath('sections.timeline.data', [])
                ->assertJsonPath('sections.timeline.projected_version', $story->version)
                ->assertJsonPath('sections.graph.state', 'pending')->assertJsonMissingPath('sections.timeline.error')
                ->assertDontSee('SECRET')->assertDontSee('private-host');
        }
    }

    public function test_processing_overview_caps_stories_newest_first_with_only_summary_fields(): void
    {
        for ($i = 0; $i < 52; $i++) {
            $story = Story::create(['title' => 'Story '.$i]);
            $story->forceFill(['created_at' => now()->subMinutes(52 - $i), 'events' => [['large' => str_repeat('x', 1000)]]])->save();
        }
        $connection = DB::connection('mongodb');
        $connection->enableQueryLog();
        $overview = app(StoryPipeline::class)->overview();
        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();
        $this->assertCount(50, $overview['stories']);
        $this->assertSame('Story 51', $overview['stories'][0]['title']);
        $this->assertSame('Story 2', $overview['stories'][49]['title']);
        $this->assertCount(1, $queries);
        $query = json_encode($queries[0]);
        $this->assertStringContainsString('processing', $query);
        $this->assertStringNotContainsString('events', $query);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/admin/processing')->assertOk()->assertSee('50 Story mới nhất')->assertSee('Story 51')->assertDontSee('Story 0<', false);
    }

    public function test_admin_inspection_initializes_groups_at_zero_without_consuming_existing_events(): void
    {
        $story = $this->ingest();
        $client = Redis::connection('nosql')->client();
        $this->assertSame([], $client->xInfo('GROUPS', StoryPipeline::STREAM));
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/admin/processing')->assertOk()
            ->assertSeeInOrder(['Dashboard', 'Health', 'Xử lý Story']);
        $groups = $client->xInfo('GROUPS', StoryPipeline::STREAM);
        $this->assertCount(2, $groups);
        foreach ($groups as $group) {
            $this->assertSame('0-0', $group['last-delivered-id']);
            $this->assertSame(0, $group['pending']);
        }
        $delivery = app(StoryPipeline::class)->stream('graph')->receive('after-inspection');
        $this->assertSame($story->events[0]['event_id'], json_decode($delivery['event'], true)['event_id']);
    }

    public function test_processing_reports_unexpected_exceptions_but_handles_missing_and_unavailable_stores(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Exceptions::fake();
        $this->post('/admin/processing/retry', ['section' => 'graph', 'id' => '1-0'])
            ->assertRedirect('/admin/processing')->assertSessionHas('error');
        Exceptions::assertNothingReported();
        // Keep the real pipeline/replay boundary; inject only the failing store adapter.
        $redis = Redis::getFacadeRoot();
        $logger = Log::getFacadeRoot();
        foreach ([new \RedisException('SECRET redis://private-host password'), new \RuntimeException('SECRET wrapper', 0, new \RedisException('SECRET private-host')), new ConnectionException('SECRET mongodb://private-host password'), new \LogicException('Unexpected adapter bug')] as $error) {
            Log::spy();
            $connection = \Mockery::mock(PhpRedisConnection::class);
            $connection->shouldReceive('client')->andThrow($error);
            $manager = \Mockery::mock(RedisManager::class);
            $manager->shouldReceive('connection')->with('nosql')->andReturn($connection);
            Redis::swap($manager);
            $this->post('/admin/processing/retry', ['section' => 'graph', 'id' => '1-0'])
                ->assertRedirect('/admin/processing')->assertSessionHas('error');
            $this->get('/admin/processing')->assertOk()->assertSee('Tạm thời không thể tải tình trạng xử lý.')
                ->assertDontSee($error->getMessage());
            Redis::clearResolvedInstances();
            Redis::swap($redis);
            if ($error instanceof \LogicException) {
                Exceptions::assertReported(fn (\LogicException $reported) => $reported === $error);
                Log::shouldNotHaveReceived('warning');
            } else {
                Exceptions::assertNothingReported();
                $class = ($error->getPrevious() ?? $error)::class;
                foreach (['retry', 'overview'] as $operation) {
                    Log::shouldHaveReceived('warning')->with('Story processing '.$operation.' unavailable.', ['exception' => $class])->once();
                }
                Log::shouldHaveReceived('warning')->twice();
            }
            Log::swap($logger);
        }
    }

    public function test_retry_validation_redirects_cleanly_and_csrf_is_enforced(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([[], ['section' => 'unknown', 'id' => '1-0'], ['section' => 'graph', 'id' => 'invalid'], ['section' => 'graph', 'id' => '1-0']] as $input) {
            $this->post('/admin/processing/retry', $input)->assertRedirect('/admin/processing')->assertSessionHas('error');
        }
        // Laravel normally bypasses CSRF in tests; enable the real check on this route.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->post('/admin/processing/retry', ['section' => 'graph', 'id' => '1-0'])->assertStatus(419);
        $this->withSession(['_token' => 'valid-test-token'])->post('/admin/processing/retry', [
            '_token' => 'valid-test-token', 'section' => 'graph', 'id' => '1-0',
        ])->assertRedirect('/admin/processing')->assertSessionHas('error');
    }
}
