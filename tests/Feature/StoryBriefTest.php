<?php

namespace Tests\Feature;

use App\Authorization\Permissions;
use App\Content\ContentCatalog;
use App\Jobs\GenerateStoryBrief;
use App\Models\Article;
use App\Models\Story;
use App\Models\User;
use App\StoryLens\Brief\BriefModel;
use App\StoryLens\Brief\Briefs;
use App\StoryLens\Brief\BriefStore;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoryBriefTest extends TestCase
{
    private const API_URL = 'https://api.example.test/v1/chat/completions';

    private const KEY = 'fixture-api-key-private';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'nosql.cassandra.keyspace' => 'vietfeed_test',
            'ai.provider' => 'fake', 'ai.require_review' => true, 'ai.max_evidence' => 8,
            'ai.openai.base_url' => 'https://api.example.test/v1', 'ai.openai.key' => self::KEY, 'ai.openai.model' => 'test',
        ]);
        Http::preventStrayRequests();
        Queue::fake([GenerateStoryBrief::class]);
    }

    public function test_ingestion_queues_only_the_final_version_of_a_story_without_running_a_model(): void
    {
        Http::fake();
        $this->mock(BriefModel::class)->shouldNotReceive('generate');
        $fixture = $this->fixture(0, 1);
        $template = $fixture['items'][0];
        $fixture['items'] = [];
        for ($i = 0; $i < 15; $i++) {
            $fixture['items'][] = ['link' => 'https://news.example.test/article/'.$i] + $template;
        }
        $this->assertSame(15, app(ContentCatalog::class)->ingest($fixture));
        $story = Story::firstOrFail();
        Queue::assertPushed(GenerateStoryBrief::class, 1);
        Queue::assertPushed(GenerateStoryBrief::class, fn ($job) => $job->storyId === $story->id
            && $job->storyVersion === 15 && $job->connection === 'redis' && $job->tries === 1 && $job->delay === 0);
        $this->assertSame(0, app(ContentCatalog::class)->ingest($fixture));
        Queue::assertPushed(GenerateStoryBrief::class, 1);
        $this->assertSame('queued', $story->brief['state']);
        $this->assertSame(15, $story->brief['story_version']);
        Http::assertNothingSent();
    }

    public function test_ingestion_staggers_distinct_stories_in_first_attachment_order(): void
    {
        Http::fake();
        $fixture = $this->fixture(0, 7);
        foreach (['a', 'b', 'a', 'c', 'b', 'c', 'a'] as $position => $key) {
            $fixture['items'][$position]['story_key'] = $key;
        }
        $this->assertSame(7, app(ContentCatalog::class)->ingest($fixture));
        $stories = Story::all()->keyBy('story_key');
        $jobs = Queue::pushed(GenerateStoryBrief::class);
        $this->assertSame([$stories['a']->id, $stories['b']->id, $stories['c']->id], $jobs->pluck('storyId')->all());
        $this->assertSame([3, 2, 2], $jobs->pluck('storyVersion')->all());
        $this->assertSame([0, 6, 12], $jobs->pluck('delay')->all());
        Http::assertNothingSent();
    }

    public function test_ingestion_survives_dispatch_failure_and_records_only_safe_diagnostics(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException(self::KEY.' Redis unavailable'));
        Log::spy();
        $story = $this->ingest(1);
        $this->assertSame(1, Article::count());
        $this->assertSame('failed', $story->brief['state']);
        $this->assertSame('queue_unavailable', $story->brief['error_category']);
        Log::shouldHaveReceived('warning')->once()->with('Story brief dispatch failed', [
            'story_id' => $story->id, 'story_version' => 1, 'exception_type' => \RuntimeException::class,
        ]);
    }

    public function test_ingestion_uses_the_real_redis_queue_and_worker_can_claim_the_payload(): void
    {
        // Restore the real queue for this test while keeping every Redis operation in DB15.
        Queue::swap(Queue::getFacadeRoot()->queue);
        $this->assertSame(15, Redis::connection(config('queue.connections.redis.connection'))->client()->getDbNum());
        $story = $this->ingest(1);
        $job = Queue::connection('redis')->pop();
        $this->assertNotNull($job);
        $job->fire();
        $job->delete();
        $this->assertSame('ready', $story->fresh()->brief['state']);
        $this->assertSame('candidate', $this->storedBrief($story->fresh()->brief['candidate_id'])['status']);
    }

    public function test_redis_retry_becomes_eligible_after_the_configured_delay(): void
    {
        Queue::swap(Queue::getFacadeRoot()->queue);
        config(['ai.provider' => 'openai', 'ai.retry_delay_seconds' => 9]);
        $story = $this->ingest(1);
        Http::fake([self::API_URL => Http::sequence()->pushStatus(503)
            ->pushResponse($this->completion($this->validOutput($story->article_ids[0])))]);
        $queue = Queue::connection('redis');
        $job = $queue->pop();
        $this->assertNotNull($job);
        $job->fire();
        $this->assertSame('queued', $story->fresh()->brief['state']);
        $this->assertNull($queue->pop());
        Http::assertSentCount(1);
        $this->travel(10)->seconds(function () use ($queue) {
            $retry = $queue->pop();
            $this->assertNotNull($retry);
            $retry->fire();
        });
        $this->assertSame('ready', $story->fresh()->brief['state']);
        $this->assertSame(0, $queue->size());
        Http::assertSentCount(2);
    }

    public function test_pages_require_no_model_and_show_cited_fallback_when_provider_is_disabled_or_down(): void
    {
        Http::fake(function () {
            throw new \LogicException('No Story request may call a provider.');
        });
        $story = $this->ingest();
        config(['ai.provider' => 'none']);
        $this->runJob($story);
        $this->assertSame('disabled', $story->fresh()->brief['error_category']);
        config(['ai.provider' => 'openai']);
        $this->mock(BriefModel::class)->shouldNotReceive('generate');
        $base = '/stories/'.$story->id;
        $this->get($base)->assertOk()->assertSeeInOrder(['Tóm tắt trích từ nguồn', 'Bài viết từ các nguồn'])
            ->assertSee('href="#article-'.$story->article_ids[0].'"', false)->assertDontSee('disabled');
        $this->getJson($base.'/sections')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('brief.kind', 'extractive')->assertJsonPath('brief.state', 'failed')
            ->assertJsonMissingPath('brief.error_category');
        $empty = Story::create(['title' => 'Story cũ không có bài']);
        $this->get('/stories/'.$empty->id)->assertOk()->assertSee('Chưa có nội dung tóm tắt.');
        $this->getJson('/stories/'.$empty->id.'/sections')->assertJsonPath('brief.kind', 'none')->assertJsonPath('brief.points', []);
        Http::assertNothingSent();
    }

    #[DataProvider('unavailableBriefs')]
    public function test_public_story_and_polling_keep_evidence_when_brief_storage_is_unavailable(string $problem): void
    {
        Http::fake();
        $story = $this->ingest(1);
        Log::spy();
        $brief = $story->brief;
        if ($problem === 'invalid field') {
            $brief = 'malformed legacy data';
        } else {
            $brief['published_id'] = $story->id;
            $lookup = $this->mock(BriefStore::class)->shouldReceive('output')->twice()
                ->withArgs(fn ($requested, $id, $status) => $requested->id === $story->id && $id === $story->id && $status === 'accepted');
            if ($problem === 'store failure') {
                $lookup->andThrow(new \RuntimeException(self::KEY));
            } else {
                $lookup->andReturn([
                    '_id' => $story->id, 'story_id' => $story->id, 'story_version' => $story->version,
                    'reviewed_by' => 'fixture-editor', 'points' => [['text' => ['unexpected']]],
                ]);
            }
        }
        $story->forceFill(['brief' => $brief])->save();
        $article = Article::findOrFail($story->article_ids[0]);
        $base = '/stories/'.$story->id;
        $this->get($base)->assertOk()->assertSee('id="story-brief" data-state="unavailable"', false)
            ->assertSeeInOrder(['Tóm tắt trích từ nguồn', 'Bài viết từ các nguồn', $article->title])->assertDontSee(self::KEY);
        $this->getJson($base.'/sections')->assertOk()->assertJsonPath('brief.kind', 'extractive')
            ->assertJsonPath('brief.state', 'unavailable')->assertJsonPath('evidence.0.title', $article->title)
            ->assertJsonPath('brief.points.0.evidence.0.id', $article->id)->assertDontSee(self::KEY);
        Log::shouldNotHaveReceived('warning');
        if ($problem !== 'invalid field') {
            Log::shouldHaveReceived('debug')->twice()->with('Story brief unavailable', [
                'exception_type' => $problem === 'store failure' ? \RuntimeException::class : \UnexpectedValueException::class,
            ]);
        }
        Http::assertNothingSent();
    }

    public static function unavailableBriefs(): iterable
    {
        foreach (['store failure', 'invalid field', 'invalid output shape'] as $problem) {
            yield $problem => [$problem];
        }
    }

    public function test_review_auth_and_csrf_cover_every_mutation(): void
    {
        $story = $this->ingest();
        $uri = '/admin/stories/'.$story->id;
        foreach (['/admin/stories', $uri] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        foreach (['accept', 'reject', 'retry'] as $action) {
            $this->post($uri.'/'.$action)->assertRedirect('/login');
        }
        $this->actingAs(User::factory()->create());
        $this->get('/admin/stories')->assertForbidden();
        $this->get($uri)->assertForbidden();
        foreach (['accept', 'reject', 'retry'] as $action) {
            $this->post($uri.'/'.$action)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin', 'permissions' => [Permissions::STORIES]]));
        $this->get('/admin/stories')->assertOk()->assertSee($story->title)->assertSee('queued');
        $this->get($uri)->assertOk()->assertSee('Tạo lại');
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        foreach (['accept', 'reject', 'retry'] as $action) {
            $this->post($uri.'/'.$action)->assertStatus(419);
        }
        $this->withSession(['_token' => 'fixture-token'])->post($uri.'/retry', ['_token' => 'fixture-token'])->assertSessionHas('success');
    }

    public function test_admin_reviews_candidates_retries_and_supersedes_a_previous_accepted_brief(): void
    {
        Http::fake();
        $story = $this->ingest();
        $this->runJob($story);
        $first = $story->fresh()->brief['candidate_id'];
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{14}7[a-f0-9-]{21}$/', $first);
        $this->assertSame('candidate', $this->storedBrief($first)['status']);
        $url = '/stories/'.$story->id.'/sections';
        $this->getJson($url)->assertJsonPath('brief.kind', 'extractive');
        $admin = User::factory()->create(['role' => 'admin', 'permissions' => [Permissions::STORIES]]);
        $this->actingAs($admin);
        $base = '/admin/stories/'.$story->id;
        $this->get($base)->assertOk()->assertSee('Đã qua kiểm tra cấu trúc và ID nguồn.')
            ->assertSee($this->storedBrief($first)['points'][0]['text'])->assertSee(Article::find($story->article_ids[0])->title);
        $this->post($base.'/accept', $this->reviewInput($story, $first, 'Đã đối chiếu nguồn.'))->assertRedirect($base)->assertSessionHas('success');
        $accepted = $this->storedBrief($first);
        $this->assertSame('accepted', $accepted['status']);
        $this->assertSame($admin->id, $accepted['reviewed_by']);
        $this->assertSame('Đã đối chiếu nguồn.', $accepted['review_note']);
        $this->assertInstanceOf(UTCDateTime::class, $accepted['reviewed_at']);
        $this->getJson($url)->assertJsonPath('brief.kind', 'ai')->assertJsonPath('brief.points.0.text', $accepted['points'][0]['text']);
        $this->get('/stories/'.$story->id)->assertSee('Tóm tắt AI, đã được biên tập viên duyệt');
        $this->get('/admin/stories')->assertOk()->assertSee('>AI<', false);

        $this->post($base.'/retry')->assertSessionHas('success');
        Queue::assertPushed(GenerateStoryBrief::class, 2);
        $this->runJob($story);
        $rejected = $story->fresh()->brief['candidate_id'];
        $this->post($base.'/reject', $this->reviewInput($story, $rejected, 'Cần nguồn rõ hơn.'))->assertSessionHas('success');
        $this->assertSame('rejected', $this->storedBrief($rejected)['status']);
        $this->assertSame('Cần nguồn rõ hơn.', $this->storedBrief($rejected)['review_note']);
        $this->assertSame($first, $story->fresh()->brief['published_id']);

        $this->post($base.'/retry')->assertSessionHas('success');
        $this->runJob($story);
        $next = $story->fresh()->brief['candidate_id'];
        $this->post($base.'/accept', $this->reviewInput($story, $next))->assertSessionHas('success');
        $this->assertSame('superseded', $this->storedBrief($first)['status']);
        $this->assertSame('accepted', $this->storedBrief($first)['review_decision']);
        $this->assertSame($next, $story->fresh()->brief['published_id']);
        $this->get($base)->assertOk()->assertSee('superseded')->assertSee('Cần nguồn rõ hơn.');
        $this->assertSame(1, DB::connection('mongodb')->getCollection('story_briefs')->countDocuments(['kind' => 'extractive']));
        $this->artisan('storylens', ['action' => 'brief-eval', '--story' => $story->id])->expectsOutputToContain('Evidence cited/sent')->assertSuccessful();
        $this->assertSame(0, Artisan::call('storylens', ['action' => 'brief-eval', '--story' => $story->id, '--json' => true]));
        $row = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)[0];
        $this->assertEquals([
            'story_id' => $story->id, 'story_version' => 2, 'points_count' => 2, 'evidence_cited' => 2,
            'evidence_sent' => 2, 'evidence_coverage' => 1, 'attempts' => 3, 'invalid_outputs' => 0,
            'invalid_output_rate' => 0, 'accepted_count' => 2, 'rejected_count' => 1,
        ], $row);
        Http::assertNothingSent();
    }

    public function test_stale_publication_and_candidate_are_refused_when_story_grows(): void
    {
        $story = $this->ingest();
        $this->runJob($story);
        $published = $story->fresh()->brief['candidate_id'];
        $this->actingAs(User::factory()->create(['role' => 'admin', 'permissions' => [Permissions::STORIES]]));
        $base = '/admin/stories/'.$story->id;
        $this->post($base.'/accept', $this->reviewInput($story, $published))->assertSessionHas('success');
        $this->post($base.'/retry')->assertSessionHas('success');
        $this->runJob($story);
        $stale = $story->fresh()->brief['candidate_id'];
        $this->assertSame(1, app(ContentCatalog::class)->ingest($this->fixture(2, 1)));
        $this->assertSame(3, $story->fresh()->version);
        $this->getJson('/stories/'.$story->id.'/sections')->assertJsonPath('brief.kind', 'extractive')->assertJsonPath('brief.state', 'queued');
        $this->post($base.'/accept', $this->reviewInput($story, $stale))
            ->assertSessionHas('error', 'Bản nháp không còn là bản hiện tại của Story. Vui lòng tải lại trang.');
        $this->assertSame('superseded', $this->storedBrief($stale)['status']);
        $this->runJob($story);
        $this->assertSame('queued', $story->fresh()->brief['state']);
    }

    public function test_lease_excludes_doubles_and_stale_in_flight_generation_cannot_write_history(): void
    {
        $story = $this->ingest(1);
        config(['ai.provider' => 'openai']);
        Http::fake([self::API_URL => function () use ($story) {
            $this->assertSame('generating', $story->fresh()->brief['state']);
            $lease = DB::connection('mongodb')->getCollection('stories')->findOne(['_id' => $story->id])['brief']['lease_until'];
            $this->assertInstanceOf(UTCDateTime::class, $lease);
            $this->runJob($story);
            app(ContentCatalog::class)->ingest($this->fixture(1, 1));

            return $this->completion($this->validOutput($story->article_ids[0]));
        }]);
        $this->runJob($story);
        Http::assertSentCount(1);
        $current = $story->fresh();
        $this->assertSame(2, $current->version);
        $this->assertSame(2, $current->brief['story_version']);
        $this->assertSame('queued', $current->brief['state']);
        $this->assertSame(0, DB::connection('mongodb')->getCollection('story_briefs')->countDocuments([]));
    }

    public function test_expired_lease_can_be_reclaimed_and_old_owner_cannot_finish(): void
    {
        $story = $this->ingest(1);
        $store = app(BriefStore::class);
        $oldOwner = $store->claim($story->id, $story->version);
        $this->assertNotNull($oldOwner);
        DB::connection('mongodb')->getCollection('stories')->updateOne(['_id' => $story->id], ['$set' => ['brief.lease_until' => new UTCDateTime(0)]]);
        $this->runJob($story);
        $this->assertSame('ready', $story->fresh()->brief['state']);
        $this->assertFalse($store->finish($oldOwner, [], [], null));
        $this->assertSame(1, DB::connection('mongodb')->getCollection('story_briefs')->countDocuments(['kind' => 'ai']));
    }

    public function test_failed_worker_only_marks_its_queued_request_or_own_active_lease(): void
    {
        $story = $this->ingest(1);
        $stale = Queue::pushed(GenerateStoryBrief::class)->last();
        $this->assertSame(1, app(ContentCatalog::class)->ingest($this->fixture(1, 1)));
        $current = Queue::pushed(GenerateStoryBrief::class)->last();
        $error = new \RuntimeException(self::KEY);
        $stale->failed($error);
        $this->assertSame('queued', $story->fresh()->brief['state']);

        $this->assertTrue(app(Briefs::class)->queue($story->id, 2));
        $replacement = Queue::pushed(GenerateStoryBrief::class)->last();
        $current->failed($error);
        $this->assertSame('queued', $story->fresh()->brief['state']);
        $this->assertNotNull(app(BriefStore::class)->claim($story->id, 2, $replacement->requestId));
        $redelivery = clone $replacement;
        $redelivery->setJob($this->mock(Job::class)->shouldReceive('attempts')->once()->andReturn(2)->getMock());
        $redelivery->failed($error);
        $this->assertSame('generating', $story->fresh()->brief['state']);

        Log::spy();
        $replacement->failed($error);
        $this->assertSame('failed', $story->fresh()->brief['state']);
        $this->assertSame('worker_failed', $story->fresh()->brief['error_category']);
        $this->assertArrayNotHasKey('lease_until', $story->fresh()->brief);
        Log::shouldHaveReceived('warning')->once()->with('Story brief worker failed', [
            'story_id' => $story->id, 'story_version' => 2, 'exception_type' => \RuntimeException::class,
        ]);

        $this->assertTrue(app(Briefs::class)->queue($story->id, 2));
        Queue::pushed(GenerateStoryBrief::class)->last()->failed(null);
        $this->assertSame('worker_failed', $story->fresh()->brief['error_category']);
    }

    public function test_admin_shows_expired_lease_as_failed_and_can_retry_it(): void
    {
        $story = $this->ingest(1);
        $this->assertNotNull(app(BriefStore::class)->claim($story->id, 1));
        $this->actingAs(User::factory()->create(['role' => 'admin', 'permissions' => [Permissions::STORIES]]));
        $base = '/admin/stories/'.$story->id;
        $this->get($base)->assertOk()->assertSee('Trạng thái generating');
        DB::connection('mongodb')->getCollection('stories')->updateOne(['_id' => $story->id], ['$set' => ['brief.lease_until' => new UTCDateTime(0)]]);
        $this->get($base)->assertOk()->assertSee('Trạng thái failed')->assertSee('Quá hạn xử lý. Có thể tạo lại.')->assertSee('Tạo lại');
        $this->get('/admin/stories')->assertOk()->assertSee('>failed<', false);
        $this->post($base.'/retry')->assertSessionHas('success');
        $this->assertSame('queued', $story->fresh()->brief['state']);
    }

    public function test_interrupted_review_history_replays_when_admin_opens_story(): void
    {
        $story = $this->ingest(1);
        $this->runJob($story);
        $candidate = $story->fresh()->brief['candidate_id'];
        $editor = User::factory()->create(['role' => 'admin', 'permissions' => [Permissions::STORIES]]);
        $this->actingAs($editor);
        // Stop at the boundary between the Story decision and the history write.
        DB::connection('mongodb')->getCollection('stories')->updateOne(['_id' => $story->id], ['$set' => [
            'brief.candidate_id' => null, 'brief.published_id' => $candidate,
            'brief.history_updates' => [[
                'id' => $candidate, 'from' => 'candidate',
                'set' => ['status' => 'accepted', 'review_decision' => 'accepted', 'reviewed_by' => $editor->id,
                    'reviewed_at' => new UTCDateTime, 'review_note' => null],
            ]],
        ]]);
        $public = '/stories/'.$story->id.'/sections';
        $this->getJson($public)->assertJsonPath('brief.kind', 'extractive');
        $this->get('/admin/stories/'.$story->id)->assertOk()->assertSee('accepted');
        $this->getJson($public)->assertJsonPath('brief.kind', 'ai');
        $this->assertSame($editor->id, $this->storedBrief($candidate)['reviewed_by']);
        $this->assertArrayNotHasKey('history_updates', $story->fresh()->brief);
    }

    public function test_admin_index_uses_one_batch_and_ignores_another_stories_publication(): void
    {
        $story = $this->ingest(1);
        config(['ai.require_review' => false]);
        $this->runJob($story);
        $other = Story::create(['title' => 'Story có con trỏ sai']);
        $other->forceFill(['version' => $story->version, 'brief' => $story->fresh()->brief])->save();
        $briefId = $story->fresh()->brief['published_id'];
        $store = $this->partialMock(BriefStore::class);
        $store->shouldReceive('acceptedOutputs')->once()->with([$briefId])->passthru();
        $store->shouldNotReceive('output');
        $this->actingAs(User::factory()->create(['role' => 'admin', 'permissions' => [Permissions::STORIES]]));
        $this->get('/admin/stories')->assertOk()->assertViewHas('stories', function ($stories) use ($story, $other) {
            $byId = $stories->keyBy('id');

            return $byId[$story->id]['published'] === 'AI' && $byId[$other->id]['published'] === '—';
        });
    }

    public function test_admin_and_publication_agree_when_canonical_evidence_is_removed(): void
    {
        $story = $this->ingest();
        config(['ai.require_review' => false]);
        $this->runJob($story);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'permissions' => [Permissions::STORIES]]));
        $public = '/stories/'.$story->id.'/sections';
        $this->getJson($public)->assertJsonPath('brief.kind', 'ai');
        $this->get('/admin/stories')->assertOk()->assertSee('>AI<', false);

        $story->forceFill(['article_ids' => [$story->article_ids[0]]])->save();
        $this->getJson($public)->assertJsonPath('brief.kind', 'extractive');
        $this->get('/admin/stories')->assertOk()->assertSee('Trích từ nguồn')->assertDontSee('>AI<', false);

        // A dangling canonical pointer must render the same empty state on both surfaces.
        Article::findOrFail($story->article_ids[0])->delete();
        $this->getJson($public)->assertJsonPath('brief.kind', 'none');
        $this->get('/admin/stories')->assertOk()->assertSee('>—<', false)->assertDontSee('Trích từ nguồn');
    }

    #[DataProvider('invalidReasons')]
    public function test_invalid_outputs_are_recorded_without_partial_text_or_credentials(string $reason): void
    {
        $story = $this->ingest();
        $id = $story->article_ids[0];
        config(['ai.provider' => 'openai', 'ai.max_evidence' => 1, 'ai.require_review' => false]);
        $output = json_decode($this->validOutput($id), true);
        $json = match ($reason) {
            'json' => '{'.self::KEY,
            'unknown_evidence' => $this->validOutput($story->article_ids[1]),
            'points_shape' => json_encode(['points' => array_fill(0, 6, $output['points'][0])]),
            'text_markup_or_url' => str_replace('trong ngày', 'tại https://example.test', $this->validOutput($id)),
            'sensitive_output' => str_replace('trong ngày', self::KEY, $this->validOutput($id)),
        };
        Http::fake([self::API_URL => $this->completion($json)]);
        Log::spy();
        $this->runJob($story);
        $brief = $story->fresh()->brief;
        $this->assertSame('failed', $brief['state']);
        $this->assertSame('invalid_output', $brief['error_category']);
        $this->assertSame($reason, $brief['validation_reason']);
        $this->assertNull($brief['candidate_id']);
        $this->assertNull($brief['published_id']);
        $attempt = iterator_to_array(app(BriefStore::class)->history($story, kind: 'ai'))[0];
        $this->assertSame([], $attempt['points']);
        $this->assertSame([$id], $attempt['evidence_ids']);
        $this->assertStringNotContainsString(self::KEY, json_encode($attempt));
        $this->getJson('/stories/'.$story->id.'/sections')->assertJsonPath('brief.kind', 'extractive')->assertDontSee(self::KEY);
        Log::shouldHaveReceived('warning')->once()->with('Story brief generation failed', [
            'story_id' => $story->id, 'story_version' => 2, 'category' => 'invalid_output', 'reason' => $reason,
        ]);
        Http::assertSentCount(1);
        Queue::assertPushed(GenerateStoryBrief::class, 1);
        $this->assertSame(0, Artisan::call('storylens', ['action' => 'brief-eval', '--all' => true, '--json' => true]));
        $row = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)[0];
        $this->assertSame(1, $row['invalid_output_rate']);
        $this->assertSame(0, $row['rejected_count']);
        $this->assertSame(1, $row['evidence_sent']);
    }

    public static function invalidReasons(): iterable
    {
        foreach (['json', 'unknown_evidence', 'points_shape', 'text_markup_or_url', 'sensitive_output'] as $reason) {
            yield $reason => [$reason];
        }
    }

    public function test_cli_sync_auto_publication_requires_valid_output_and_uses_a_truthful_label(): void
    {
        Http::fake();
        $story = $this->ingest();
        config(['ai.require_review' => false]);
        Queue::swap(Queue::getFacadeRoot()->queue);
        $this->artisan('storylens', ['action' => 'brief', '--all' => true, '--sync' => true])
            ->expectsOutput('Ran brief generation for 1 of 1 Stories')->assertSuccessful();
        $this->assertSame('ready', $story->fresh()->brief['state']);
        $this->get('/stories/'.$story->id)->assertSee('Tóm tắt AI đã qua kiểm tra tự động')->assertDontSee('đã được biên tập viên duyệt');
        config(['ai.require_review' => true]);
        $this->getJson('/stories/'.$story->id.'/sections')->assertJsonPath('brief.kind', 'extractive');
        Http::assertNothingSent();
    }

    #[DataProvider('transientFailures')]
    public function test_transient_provider_failure_queues_exactly_one_retry(string $category, bool $succeeds): void
    {
        $story = $this->ingest(1);
        config(['ai.provider' => 'openai']);
        $sequence = Http::sequence();
        match ($category) {
            'timeout' => $sequence->pushFailedConnection('cURL error 28: Operation timed out'),
            'network' => $sequence->pushFailedConnection('Could not resolve host'),
            default => $sequence->pushStatus(503),
        };
        if ($succeeds) {
            $sequence->pushResponse($this->completion($this->validOutput($story->article_ids[0])));
        } else {
            $sequence->pushStatus(503);
        }
        Http::fake([self::API_URL => $sequence]);
        $first = Queue::pushed(GenerateStoryBrief::class)->sole();
        app()->call([$first, 'handle']);
        $pending = $story->fresh()->brief;
        $this->assertSame('queued', $pending['state']);
        $this->assertSame($category, $pending['error_category']);
        $this->assertArrayNotHasKey('lease_until', $pending);
        $first->failed(new \RuntimeException('Old delivery failed after scheduling a retry'));
        $withoutRequestId = new GenerateStoryBrief($story->id, $story->version, providerAttempt: 2);
        app()->call([$withoutRequestId, 'handle']);
        $withoutRequestId->failed(new \RuntimeException('Another delivery lacks the request ID'));
        $this->assertSame($pending['revision'], $story->fresh()->brief['revision']);
        Queue::assertPushed(GenerateStoryBrief::class, 2);
        $retry = Queue::pushed(GenerateStoryBrief::class)->last();
        $this->assertSame(30, $retry->delay);
        $this->assertSame($first->storyVersion, $retry->storyVersion);
        $this->assertSame($first->requestId, $retry->requestId);
        $this->assertSame(2, $retry->providerAttempt);
        app()->call([$retry, 'handle']);
        app()->call([$retry, 'handle']);
        Http::assertSequencesAreEmpty();
        Queue::assertPushed(GenerateStoryBrief::class, 2);
        $brief = $story->fresh()->brief;
        $this->assertSame($succeeds ? 'ready' : 'failed', $brief['state']);
        $this->assertSame($succeeds ? null : 'http_5xx', $brief['error_category']);
        $this->assertSame($succeeds, is_string($brief['candidate_id']));
        $history = iterator_to_array(app(BriefStore::class)->history($story, kind: 'ai'));
        $this->assertSame([2, 1], array_column($history, 'provider_attempt'));
        $this->assertSame([$first->requestId, $first->requestId], array_column($history, 'request_id'));
        $this->assertSame([$succeeds ? null : 'http_5xx', $category], array_column($history, 'error_category'));
        $this->assertSame([], $history[1]['points']);
    }

    public static function transientFailures(): iterable
    {
        yield '503 then success' => ['http_5xx', true];
        yield '503 then 503' => ['http_5xx', false];
        yield 'timeout then success' => ['timeout', true];
        yield 'network error then success' => ['network', true];
    }

    #[DataProvider('permanentHttpFailures')]
    public function test_permanent_http_failure_never_queues_a_retry(int $status, string $category): void
    {
        $story = $this->ingest(1);
        config(['ai.provider' => 'openai']);
        Http::fake([self::API_URL => Http::sequence()->pushStatus($status)]);
        $this->runJob($story);
        Http::assertSentCount(1);
        Queue::assertPushed(GenerateStoryBrief::class, 1);
        $brief = $story->fresh()->brief;
        $this->assertSame('failed', $brief['state']);
        $this->assertSame($category, $brief['error_category']);
        $this->assertSame(1, app(Briefs::class)->evaluate($story)['attempts']);
    }

    public static function permanentHttpFailures(): iterable
    {
        yield 'rate limit' => [429, 'http_429'];
        yield 'payment' => [402, 'http_402'];
        yield 'other client error' => [400, 'http_4xx'];
    }

    public function test_retry_dispatch_failure_preserves_the_first_attempt_in_history(): void
    {
        $story = $this->ingest(1);
        config(['ai.provider' => 'openai']);
        Http::fake([self::API_URL => Http::sequence()->pushStatus(503)]);
        Bus::shouldReceive('dispatch')->once()->withArgs(fn ($job) => $job->providerAttempt === 2)
            ->andThrow(new \RuntimeException(self::KEY));
        $this->runJob($story);
        Http::assertSentCount(1);
        $this->assertSame('failed', $story->fresh()->brief['state']);
        $this->assertSame('queue_unavailable', $story->fresh()->brief['error_category']);
        $history = iterator_to_array(app(BriefStore::class)->history($story, kind: 'ai'));
        $this->assertCount(1, $history);
        $this->assertSame('http_5xx', $history[0]['error_category']);
    }

    #[DataProvider('requestChanges')]
    public function test_delayed_retry_is_discarded_if_its_version_or_request_changes(bool $versionChanges): void
    {
        $story = $this->ingest(1);
        config(['ai.provider' => 'openai']);
        Http::fake([self::API_URL => Http::sequence()->pushStatus(503)]);
        $this->runJob($story);
        $retry = Queue::pushed(GenerateStoryBrief::class)->last();
        $this->assertSame(2, $retry->providerAttempt);
        if ($versionChanges) {
            $this->assertSame(1, app(ContentCatalog::class)->ingest($this->fixture(1, 1)));
        } else {
            $this->assertTrue(app(Briefs::class)->queue($story->id, 1));
        }
        $current = $story->fresh()->brief;
        app()->call([$retry, 'handle']);
        $retry->failed(new \RuntimeException('Stale retry job'));
        Http::assertSentCount(1);
        $this->assertSame($current['revision'], $story->fresh()->brief['revision']);
        $this->assertSame('queued', $story->fresh()->brief['state']);
        $this->assertSame(1, app(Briefs::class)->evaluate($story->fresh())['attempts']);
    }

    public static function requestChanges(): iterable
    {
        yield 'new Story version' => [true];
        yield 'replacement on the same Story version' => [false];
    }

    public function test_cli_sync_retries_in_process_after_its_shorter_delay(): void
    {
        $story = $this->ingest(1);
        config(['ai.provider' => 'openai']);
        Http::fake([self::API_URL => Http::sequence()->pushStatus(503)
            ->pushResponse($this->completion($this->validOutput($story->article_ids[0])))]);
        Queue::swap(Queue::getFacadeRoot()->queue);
        Sleep::fake();
        try {
            $this->artisan('storylens', ['action' => 'brief', '--story' => $story->id, '--sync' => true])->assertSuccessful();
            Sleep::assertSleptTimes(1);
            Sleep::assertSlept(fn ($duration) => $duration->totalSeconds === 3.0);
        } finally {
            Sleep::fake(false);
        }
        Http::assertSentCount(2);
        $this->assertSame('ready', $story->fresh()->brief['state']);
        $this->assertSame(0, Queue::connection('redis')->size());
        $this->assertSame(2, app(Briefs::class)->evaluate($story)['attempts']);
    }

    public function test_cli_spaces_and_bounds_bulk_generation_and_bounds_evaluation(): void
    {
        Http::fake();
        $first = Story::create(['title' => 'Oldest Story']);
        $first->forceFill(['created_at' => now()->subDay()])->save();
        for ($i = 1; $i <= 50; $i++) {
            Story::create(['title' => 'Story '.$i]);
        }
        $this->artisan('storylens', ['action' => 'brief', '--all' => true])
            ->expectsOutput('Queued brief generation for 50 of 50 Stories')->assertSuccessful();
        Queue::assertPushed(GenerateStoryBrief::class, 50);
        $this->assertSame(range(0, 294, 6), Queue::pushed(GenerateStoryBrief::class)->pluck('delay')->all());
        Queue::assertNotPushed(GenerateStoryBrief::class, fn ($job) => $job->storyId === $first->id);
        $this->artisan('storylens', ['action' => 'brief', '--story' => $first->id])->assertSuccessful();
        Queue::assertPushed(GenerateStoryBrief::class, fn ($job) => $job->storyId === $first->id);
        $this->assertSame(0, Artisan::call('storylens', ['action' => 'brief-eval', '--json' => true]));
        $this->assertCount(50, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(0, Artisan::call('storylens', ['action' => 'brief-eval', '--limit' => 51, '--json' => true]));
        $this->assertCount(51, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    public function test_evaluation_counts_transport_attempts_but_only_invalid_output_in_its_numerator(): void
    {
        $story = $this->ingest(1);
        config(['ai.provider' => 'openai']);
        $requests = 0;
        Http::fake([self::API_URL => function () use (&$requests) {
            return match (++$requests) {
                1 => throw new \RuntimeException(self::KEY),
                2 => Http::failedConnection('cURL error 28: Operation timed out'),
                3 => $this->completion('{invalid'),
            };
        }]);
        foreach (['network', 'timeout', 'invalid_output'] as $category) {
            $this->assertTrue(app(Briefs::class)->queue($story->id, 1));
            $this->runJob($story);
            $this->assertSame($category, $story->fresh()->brief['error_category']);
        }
        $row = app(Briefs::class)->evaluate($story);
        $this->assertSame(3, $row['attempts']);
        $this->assertSame(1, $row['invalid_outputs']);
        $this->assertEqualsWithDelta(1 / 3, $row['invalid_output_rate'], 0.0001);
        $this->assertSame(3, $requests);
    }

    private function ingest(int $count = 2): Story
    {
        app(ContentCatalog::class)->ingest($this->fixture(0, $count));

        return Story::firstOrFail();
    }

    private function fixture(int $offset, int $count): array
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/storylens-rss-v1.json')), true);
        $fixture['items'] = array_slice($fixture['items'], $offset, $count);

        return $fixture;
    }

    private function runJob(Story $story): void
    {
        app()->call([new GenerateStoryBrief($story->id, $story->version), 'handle']);
    }

    private function storedBrief(string $id): array
    {
        return (array) DB::connection('mongodb')->getCollection('story_briefs')->findOne(['_id' => $id]);
    }

    private function reviewInput(Story $story, string $id, ?string $note = null): array
    {
        return ['candidate_id' => $id, 'story_version' => $story->version, 'note' => $note];
    }

    private function validOutput(string $id): string
    {
        return json_encode(['points' => [
            ['text' => 'Các nguồn ghi nhận sự kiện vào sáng nay.', 'evidence_ids' => [$id]],
            ['text' => 'Lịch trình tiếp theo được công bố trong ngày.', 'evidence_ids' => [$id]],
        ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function completion(string $json): PromiseInterface
    {
        return Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $json]]]]);
    }
}
