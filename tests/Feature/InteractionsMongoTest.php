<?php

namespace Tests\Feature;

use App\Interaction\InteractionActivity;
use App\Interaction\InteractionEvent;
use App\Interaction\InteractionStream;
use App\Interaction\InteractionToggle;
use App\Interaction\Trending;
use App\Models\Bookmark;
use App\Models\Boost;
use App\Processing\StreamConsumer;
use App\StoryProcessing\CassandraClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use MongoDB\Driver\Exception\BulkWriteException;
use Ramsey\Uuid\Uuid;
use Tests\Concerns\ModerationFixtures;
use Tests\TestCase;

class InteractionsMongoTest extends TestCase
{
    use ModerationFixtures;

    private array $partitions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpModerationFixtures();
        config(['nosql.cassandra.keyspace' => 'vietfeed_test']);
        $this->travelTo(now()->utc()->startOfDay()->addHour());
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->partitions as [$user, $article]) {
                $client = app(CassandraClient::class);
                $keyspace = $client->keyspace();
                $client->execute("DELETE FROM $keyspace.activity_by_user_month WHERE user_id = ? AND year_month = ?", [$user, now('UTC')->format('Y-m')]);
                $client->execute("DELETE FROM $keyspace.activity_by_article_day WHERE article_id = ? AND activity_day = ?", [$article, now('UTC')->format('Y-m-d')]);
            }
        } finally {
            try {
                $this->travelBack();
                $this->flushTestDatabase(Redis::connection('nosql'));
            } finally {
                parent::tearDown();
            }
        }
    }

    private function published(): array
    {
        $entries = Redis::connection('nosql')->client()->xRange(InteractionStream::STREAM, '-', '+');

        return array_map(fn ($entry) => json_decode($entry['event'], true, flags: JSON_THROW_ON_ERROR), array_values($entries));
    }

    public function test_web_toggles_publish_exactly_once_and_keep_responses_counts_and_sanctions(): void
    {
        $article = $this->article();
        $user = $this->user();
        $other = $this->user();
        $this->actingAs($user);

        foreach (['bookmarks' => Bookmark::class, 'boosts' => Boost::class] as $route => $model) {
            $model::create(['user_id' => $other->id, 'article_id' => $article->id]);
            $this->postJson(route($route.'.toggle'), ['article_id' => $article->id])->assertOk()->assertExactJson(['action' => 'added', 'count' => 2]);
            $entry = $model::where('user_id', $user->id)->sole();
            $this->assertSame(7, Uuid::fromString($entry->id)->getVersion());
            $this->assertTrue($entry->user->is($user));
            $this->assertTrue($entry->article->is($article));
            $this->postJson(route($route.'.toggle'), ['article_id' => $article->id])->assertOk()->assertExactJson(['action' => 'removed', 'count' => 1]);
            $this->postJson(route($route.'.toggle'), ['article_id' => 'does-not-exist'])->assertUnprocessable();
        }
        $this->sanctionFor($user, $this->admin());
        $this->postJson(route('bookmarks.toggle'), ['article_id' => $article->id])->assertForbidden()->assertJsonPath('error', 'restricted');
        $this->postJson(route('boosts.toggle'), ['article_id' => $article->id])->assertForbidden()->assertJsonPath('error', 'restricted');

        $events = $this->published();
        $this->assertSame(['BOOKMARKED', 'UNBOOKMARKED', 'BOOSTED', 'UNBOOSTED'], array_column($events, 'type'));
        $this->assertCount(4, array_unique(array_column($events, 'event_id')));
        foreach ($events as $event) {
            InteractionEvent::fromArray($event);
            $this->assertSame(7, Uuid::fromString($event['event_id'])->getVersion());
            $this->assertSame($user->id, $event['user_id']);
            $this->assertSame($article->id, $event['article_id']);
            $this->assertSame(now('UTC')->format('Y-m-d\TH:i:s.v\Z'), $event['occurred_at']);
            $this->assertFalse($event['backfill']);
        }
        foreach (array_chunk($events, 2) as [$add, $remove]) {
            $this->assertSame($add['aggregate_id'], $remove['aggregate_id']);
            $this->assertSame([1, 2], [$add['aggregate_version'], $remove['aggregate_version']]);
        }
    }

    public function test_interleaved_adds_and_deletes_retry_and_each_change_gets_one_event(): void
    {
        $toggle = app(InteractionToggle::class);
        $key = ['user_id' => $this->user()->id, 'article_id' => $this->article()->id];

        // Run the competing request after the outer read and before its actual insert.
        $insertEvent = 'eloquent.creating: '.Boost::class;
        $nested = null;
        Event::listen($insertEvent, function () use ($insertEvent, $toggle, $key, &$nested) {
            Event::forget($insertEvent);
            $nested = $toggle->run(Boost::class, ...array_values($key));
        });
        $this->assertSame(['action' => 'removed', 'count' => 0], $toggle->run(Boost::class, ...array_values($key)));
        $this->assertSame(['action' => 'added', 'count' => 1], $nested);
        $this->assertSame(0, Boost::count());

        $toggle->run(Boost::class, ...array_values($key));
        $old = Boost::sole();
        // Run the second delete before the outer request can delete the row it read.
        $readEvent = 'eloquent.retrieved: '.Boost::class;
        Event::listen($readEvent, function () use ($readEvent, $toggle, $key, &$nested) {
            Event::forget($readEvent);
            $nested = $toggle->run(Boost::class, ...array_values($key));
        });
        $this->assertSame(['action' => 'added', 'count' => 1], $toggle->run(Boost::class, ...array_values($key)));
        $this->assertSame(['action' => 'removed', 'count' => 0], $nested);
        $this->assertNotSame($old->id, Boost::sole()->id);
        $this->assertSame(['BOOSTED', 'UNBOOSTED', 'BOOSTED', 'UNBOOSTED', 'BOOSTED'], array_column($this->published(), 'type'));

        try {
            Boost::create($key);
            $this->fail('Mongo must reject a duplicate natural key');
        } catch (BulkWriteException $e) {
            $this->assertSame(11000, $e->getCode());
        }
        $this->assertSame(1, Boost::count());
        $this->assertCount(5, $this->published());
    }

    public function test_redis_outage_keeps_action_successful_and_reemit_recovers_the_original_add(): void
    {
        $article = $this->article();
        $user = $this->user();
        $this->actingAs($user);
        $manager = Redis::getFacadeRoot();
        Log::spy();
        Redis::shouldReceive('connection')->with('nosql')->andThrow(new \RedisException('Synthetic transport outage with private diagnostic'));
        try {
            $this->postJson(route('boosts.toggle'), ['article_id' => $article->id])->assertOk()->assertExactJson(['action' => 'added', 'count' => 1]);
        } finally {
            Redis::swap($manager);
        }
        Log::shouldHaveReceived('warning')->once()->with('Interaction publish failed', ['exception_type' => \RedisException::class]);
        $membership = Boost::sole();
        $this->assertSame([], $this->published());

        $this->artisan('interactions', ['action' => 'reemit'])->expectsOutput('Published 1 current memberships, 0 failed')->assertSuccessful();
        $this->artisan('interactions', ['action' => 'reemit'])->assertSuccessful();
        $events = $this->published();
        $this->assertSame([$membership->id, $membership->id], array_column($events, 'event_id'));
        $this->assertSame($events[0], $events[1]);

        $this->startActivity($user->id, $article->id);
        $stream = app(InteractionStream::class);
        $this->assertSame(2, $stream->consume('reemit', 10));
        $this->assertCount(1, $this->activityFor('user', $user->id));
        $this->assertSame([$article->id => 5.0], app(Trending::class)->top());
    }

    public function test_lost_remove_is_repaired_by_rebuild_and_history_contains_only_delivered_events(): void
    {
        $article = $this->article();
        $user = $this->user();
        $this->startActivity($user->id, $article->id);
        $this->actingAs($user);
        $this->postJson(route('boosts.toggle'), ['article_id' => $article->id])->assertOk();
        $stream = app(InteractionStream::class);
        $this->assertSame(1, $stream->consume('add'));
        $this->assertSame([$article->id => 5.0], app(Trending::class)->top());

        $redis = Redis::connection('nosql');
        $redis->del(InteractionStream::STREAM);
        $redis->set(InteractionStream::STREAM, 'wrong-type');
        $this->postJson(route('boosts.toggle'), ['article_id' => $article->id])->assertOk()->assertExactJson(['action' => 'removed', 'count' => 0]);
        $redis->del(InteractionStream::STREAM);
        $this->artisan('interactions', ['action' => 'rebuild'])->expectsOutput('Rebuilt 0 active MongoDB boosts')->assertSuccessful();
        $this->assertSame([], app(Trending::class)->top());
        $this->assertSame(['BOOSTED'], array_column($this->activityFor('article', $article->id), 'type'));
    }

    public function test_projection_retries_to_dlq_then_replays_without_duplicate_activity_or_rank(): void
    {
        $user = $this->user();
        $article = $this->article();
        $this->startActivity($user->id, $article->id);
        $this->actingAs($user)->postJson(route('boosts.toggle'), ['article_id' => $article->id])->assertOk();
        $event = $this->published()[0];
        $stream = new InteractionStream;
        $redis = Redis::connection('nosql')->client();
        $redis->set(Trending::KEY, 'invalid-zset');
        $this->assertSame(1, $stream->consume('initial'));
        $this->assertSame(1, $stream->lengths()['pending']);
        for ($attempt = 2; $attempt <= StreamConsumer::MAX_ATTEMPTS; $attempt++) {
            $pending = $stream->pending()[0];
            $redis->xClaim($stream::STREAM, $stream::GROUP, $pending[1], 0, [$pending[0]], ['IDLE' => 100000, 'JUSTID']);
            $this->assertSame(1, $stream->consume('retry'));
        }
        $this->assertSame(['stream' => 0, 'pending' => 0, 'dead_letters' => 1], $stream->lengths());
        $this->assertCount(1, $this->activityFor('user', $user->id));
        $this->assertCount(1, $this->activityFor('article', $article->id));
        $redis->del(Trending::KEY);
        $this->artisan('interactions', ['action' => 'replay', '--id' => array_key_first($stream->deadLetters())])->assertSuccessful();
        $this->assertSame(1, $stream->consume('healthy'));
        $stream->publish($event);
        $this->assertSame(1, $stream->consume('duplicate'));
        $this->assertSame([$article->id => 5.0], app(Trending::class)->top());
        $this->assertSame(['stream' => 0, 'pending' => 0, 'dead_letters' => 0], $stream->lengths());
        foreach ([$this->activityFor('user', $user->id), $this->activityFor('article', $article->id)] as $rows) {
            $this->assertCount(1, $rows);
            $this->assertSame($event['event_id'], (string) $rows[0]['event_id']);
            $this->assertSame('BOOSTED', $rows[0]['type']);
            $this->assertSame($user->id, $rows[0]['user_id']);
            $this->assertSame($article->id, $rows[0]['article_id']);
            $this->assertFalse($rows[0]['backfill']);
        }
    }

    public function test_real_trending_orders_home_and_rebuild_survives_pending_or_out_of_order_events(): void
    {
        $older = $this->article('older');
        $older->update(['published_at' => now()->subHour()]);
        $newer = $this->article('newer');
        $user = $this->user();
        $other = $this->user();
        $this->startActivity($user->id, $older->id);
        $oldId = $older->id;
        $this->partitions[] = [$other->id, $oldId];
        $this->partitions[] = [$user->id, $newer->id];
        $stream = app(InteractionStream::class);
        $ranking = app(Trending::class);

        $this->actingAs($user)->postJson(route('boosts.toggle'), ['article_id' => $newer->id])->assertOk();
        $this->postJson(route('boosts.toggle'), ['article_id' => $oldId])->assertOk();
        $this->actingAs($other)->postJson(route('boosts.toggle'), ['article_id' => $oldId])->assertOk();
        $toRemove = $this->published()[2];
        $this->assertSame(3, $stream->consume('rank', 10));
        $this->assertSame([$oldId => 10.0, $newer->id => 5.0], $ranking->top());
        $this->assertSame([$oldId, $newer->id], $this->get('/')->assertOk()->viewData('trending')->pluck('id')->all());

        $this->postJson(route('boosts.toggle'), ['article_id' => $oldId])->assertOk()->assertExactJson(['action' => 'removed', 'count' => 1]);
        $this->assertSame([$oldId, $newer->id], $this->get('/')->assertOk()->viewData('trending')->pluck('id')->all());
        $redis = Redis::connection('nosql');
        $marker = 'version:'.$toRemove['aggregate_id'];
        $this->assertSame(-1.0, $redis->zscore(Trending::KEY, $marker));
        $this->assertSame(1, $stream->consume('remove'));
        $this->assertFalse($redis->zscore(Trending::KEY, $marker));
        $this->assertEquals([$oldId => 5.0, $newer->id => 5.0], $ranking->top());
        $this->assertSame([$newer->id, $oldId], $this->get('/')->assertOk()->viewData('trending')->pluck('id')->all());

        // DB15 can lose the entire projection while the stream still has an old add.
        $this->postJson(route('boosts.toggle'), ['article_id' => $oldId])->assertOk();
        $this->postJson(route('boosts.toggle'), ['article_id' => $oldId])->assertOk();
        [$staleAdd, $staleRemove] = $this->published();
        Redis::connection('nosql')->del(Trending::KEY, InteractionStream::STREAM);
        $this->artisan('interactions', ['action' => 'rebuild'])->expectsOutput('Rebuilt 2 active MongoDB boosts')->assertSuccessful();
        $stream->publish($staleAdd);
        $this->assertSame(1, $stream->consume('late-add'));
        $this->assertEquals([$oldId => 5.0, $newer->id => 5.0], $ranking->top());
        $stream->publish($staleRemove);
        $stream->publish($staleAdd);
        $this->assertSame(2, $stream->consume('out-of-order', 10));
        $this->assertFalse($redis->zscore(Trending::KEY, 'version:'.$staleAdd['aggregate_id']));
        $this->assertEquals([$oldId => 5.0, $newer->id => 5.0], $ranking->top());

        Redis::connection('nosql')->del(InteractionStream::STREAM);
        $this->actingAs($user)->postJson(route('bookmarks.toggle'), ['article_id' => $oldId])->assertOk();
        $this->postJson(route('bookmarks.toggle'), ['article_id' => $oldId])->assertOk();
        $this->assertSame(2, $stream->consume('bookmarks', 10));
        $this->assertEqualsCanonicalizing(['BOOSTED', 'BOOSTED', 'BOOKMARKED', 'UNBOOKMARKED'], array_column($this->activityFor('user', $user->id), 'type'));
        $this->assertEquals([$oldId => 5.0, $newer->id => 5.0], $ranking->top());
    }

    public function test_evicted_ranking_ignores_partial_scores_and_home_rebuilds_from_mongo(): void
    {
        $older = $this->article('older');
        $older->update(['published_at' => now()->subHour()]);
        $newer = $this->article('newer');
        foreach ([[$older, 3], [$newer, 2]] as [$article, $count]) {
            for ($i = 0; $i < $count; $i++) {
                Boost::create(['article_id' => $article->id, 'user_id' => $this->user()->id]);
            }
        }
        $redis = Redis::connection('nosql');
        $ranking = app(Trending::class);
        $this->artisan('interactions', ['action' => 'rebuild'])->expectsOutput('Rebuilt 5 active MongoDB boosts')->assertSuccessful();
        $this->assertSame(1, $redis->del(Trending::KEY));

        $added = Boost::create(['article_id' => $older->id, 'user_id' => $this->user()->id]);
        $ranking->apply(InteractionEvent::membership($added, added: true));
        // Ranking this partial Redis score against the newer article's
        // Mongo fallback (10) would incorrectly put the newer article first.
        $this->assertSame([$older->id => 5.0], $ranking->top());
        $this->assertSame([$older->id, $newer->id], $this->get('/')->assertOk()->viewData('trending')->pluck('id')->all());
        $this->assertSame([$older->id => 20.0, $newer->id => 10.0], $ranking->top());
    }

    public function test_demo_rebuild_and_top_leave_the_live_ranking_intact(): void
    {
        $article = $this->article();
        Boost::create(['article_id' => $article->id, 'user_id' => $this->user()->id]);
        $this->artisan('interactions', ['action' => 'rebuild'])->assertSuccessful();

        $this->artisan('interactions', ['action' => 'rebuild', '--synthetic' => true])
            ->expectsOutput('Rebuilt 5 synthetic events')->assertSuccessful();
        $this->artisan('interactions', ['action' => 'top', '--synthetic' => true])
            ->expectsOutput(json_encode(['article-a' => 7.0, 'article-b' => 6.0], JSON_PRETTY_PRINT))->assertSuccessful();
        $this->artisan('interactions', ['action' => 'top'])
            ->expectsOutput(json_encode([$article->id => 5.0], JSON_PRETTY_PRINT))->assertSuccessful();
    }

    public function test_web_toggles_share_a_per_user_limit_with_json_retry_feedback(): void
    {
        $article = $this->article();
        $payload = ['article_id' => $article->id];
        $this->actingAs($this->user());
        for ($i = 0; $i < 15; $i++) {
            $this->postJson(route('bookmarks.toggle'), $payload)->assertOk();
            $this->postJson(route('boosts.toggle'), $payload)->assertOk();
        }
        foreach (['bookmarks.toggle', 'boosts.toggle'] as $route) {
            $this->postJson(route($route), $payload)->assertTooManyRequests()
                ->assertHeader('Retry-After')
                ->assertJsonPath('message', 'Bạn thao tác quá nhanh. Vui lòng thử lại sau.');
        }
        $this->assertCount(30, $this->published());
        $this->actingAs($this->user())->postJson(route('boosts.toggle'), $payload)->assertOk();
    }

    public function test_indexes_are_rerunnable_and_membership_references_are_bson_strings(): void
    {
        $migration = require database_path('migrations/2026_11_18_210001_create_mongodb_interaction_indexes.php');
        $migration->up();
        $migration->up();
        foreach ([Bookmark::class, Boost::class] as $model) {
            $membership = $model::withoutEvents(fn () => $model::create(['user_id' => 7, 'article_id' => 42]));
            $this->assertSame(7, Uuid::fromString($membership->id)->getVersion());
            $this->assertSame('7', $membership->fresh()->user_id);
            $this->assertSame('42', $membership->fresh()->article_id);
            $collection = DB::connection('mongodb')->getCollection($membership->getTable());
            $indexes = collect(iterator_to_array($collection->listIndexes()))->keyBy(fn ($index) => $index->getName());
            $this->assertTrue($indexes['user_id_1_article_id_1']->isUnique());
            $this->assertTrue($indexes->has(['user_id_1_created_at_-1', 'article_id_1']));
        }
    }

    public function test_admin_counts_group_mongo_once_on_application_routes(): void
    {
        $admin = $this->admin();
        $user = $this->user();
        $article = $this->article();
        foreach ([Bookmark::class, Boost::class] as $model) {
            $model::create(['user_id' => $admin->id, 'article_id' => $article->id]);
        }
        $this->actingAs($admin);
        // Gate 9.5 slice 5: the legacy SQL rows and SQL query log are gone (no SQL connection; TestCase guard).
        $mongo = DB::connection('mongodb');
        $mongo->enableQueryLog();
        try {
            foreach (['/', '/bookmarks', '/articles/'.$article->slug, '/admin/dashboard', '/admin/articles?sort=boosts', '/admin/users'] as $path) {
                $mongo->flushQueryLog();
                $response = $this->get($path)->assertOk();
                $counts = collect($mongo->getQueryLog())->pluck('query');
                $this->assertCount(1, $counts->filter(fn ($q) => str_contains($q, 'aggregate') && str_contains($q, 'bookmarks')), $path);
                $this->assertCount($path === '/admin/users' ? 0 : 1,
                    $counts->filter(fn ($q) => str_contains($q, 'aggregate') && str_contains($q, 'boosts')), $path);
                if ($path === '/admin/dashboard') {
                    $response->assertViewHas('stats', fn ($stats) => $stats['bookmarks'] === 1 && $stats['boosts'] === 1);
                } elseif ($path === '/admin/users') {
                    $users = $response->viewData('users')->keyBy('id');
                    $this->assertSame([1, 0], [$users[$admin->id]->bookmarks_count, $users[$user->id]->bookmarks_count]);
                }
            }
        } finally {
            $mongo->disableQueryLog();
        }
    }

    private function startActivity(string $user, string $article): void
    {
        $this->artisan('interactions', ['action' => 'schema'])->assertSuccessful();
        $this->artisan('interactions', ['action' => 'schema'])->assertSuccessful();
        $this->partitions[] = [$user, $article];
    }

    private function activityFor(string $kind, string $id): array
    {
        $client = app(CassandraClient::class);
        $keyspace = $client->keyspace();
        [$table, $column, $bucket, $token] = $kind === 'user'
            ? [InteractionActivity::BY_USER, 'user_id', 'year_month', now('UTC')->format('Y-m')]
            : [InteractionActivity::BY_ARTICLE, 'article_id', 'activity_day', now('UTC')->format('Y-m-d')];

        return $client->rows("SELECT * FROM $keyspace.$table WHERE $column = ? AND $bucket = ?", [$id, $token]);
    }
}
