<?php

namespace Tests\Feature;

use App\Interaction\InteractionEvent;
use App\Interaction\InteractionReplaySource;
use App\Interaction\InteractionStream;
use App\Interaction\Trending;
use App\Models\Article;
use App\Models\Bookmark;
use App\Models\Story;
use App\Models\User;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\GuardsRedisTestDatabase;
use Tests\TestCase;

class RedisStreamsTest extends TestCase
{
    use GuardsRedisTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolateRedis(['nosql', 'default', 'cache']);
        $this->flushTestDatabase(Redis::connection('nosql'));
    }

    protected function tearDown(): void
    {
        try {
            $this->flushTestDatabase(Redis::connection('nosql'));
        } finally {
            parent::tearDown();
        }
    }

    private function event(string $type = 'view', string $article = 'article-a', int $version = 1, ?string $aggregate = null): array
    {
        return ['event_id' => (string) Str::uuid7(), 'type' => $type, 'aggregate_id' => $aggregate ?? (string) Str::uuid7(), 'aggregate_version' => $version, 'occurred_at' => '2026-11-15T00:00:00Z', 'article_id' => $article];
    }

    public function test_trending_is_idempotent_and_rejects_stale_versions(): void
    {
        $trending = app(Trending::class);
        $event = $this->event('boost');
        $trending->apply(InteractionEvent::fromArray($event));
        $trending->apply(InteractionEvent::fromArray($event));
        $event['event_id'] = (string) Str::uuid7();
        $trending->apply(InteractionEvent::fromArray($event));
        $trending->apply(InteractionEvent::fromArray($this->event()));
        $this->assertSame(['article-a' => 6.0], $trending->top(10, synthetic: true));
        $event['aggregate_version'] = 3;
        $event['event_id'] = (string) Str::uuid7();
        $trending->apply(InteractionEvent::fromArray($event));
        $event['aggregate_version'] = 2;
        $event['event_id'] = (string) Str::uuid7();
        $trending->apply(InteractionEvent::fromArray($event));
        $this->assertSame(['article-a' => 11.0], $trending->top(10, synthetic: true));
    }

    public function test_poison_retries_with_backoff_then_ends_in_one_dead_letter(): void
    {
        $stream = new InteractionStream(retryMilliseconds: 1000);
        $poison = $this->event('poison');
        $stream->publish($poison);
        $this->assertSame(1, $stream->consume('worker-a'));
        $this->assertSame(0, $stream->consume('worker-b'));
        for ($attempt = 2; $attempt <= 5; $attempt++) {
            $pending = $stream->pending()[0];
            Redis::connection('nosql')->client()->xClaim(InteractionStream::STREAM, InteractionStream::GROUP, $pending[1], 0, [$pending[0]], ['IDLE' => 100000, 'JUSTID']);
            $this->assertSame(1, $stream->consume('worker-b'));
        }
        $this->assertSame(['stream' => 0, 'pending' => 0, 'dead_letters' => 1], $stream->lengths());
        $dead = array_values($stream->deadLetters())[0];
        $this->assertSame(5, (int) $dead['attempts']);
        $this->assertSame($poison, json_decode($dead['event'], true));
        $this->assertStringContainsString('Unsupported interaction type', $dead['last_error']);
        $this->assertSame(0, $stream->consume('worker-c'));
        $this->assertSame(1, $stream->lengths()['dead_letters']);
    }

    public function test_interrupted_applied_delivery_recovers_without_inflating_score(): void
    {
        $stream = new InteractionStream;
        $stream->publish($this->event('boost'));
        $delivery = $stream->receive('interrupted');
        app(Trending::class)->apply(InteractionEvent::fromArray(json_decode($delivery['event'], true)));
        $this->assertSame(1, $stream->lengths()['pending']);
        Redis::connection('nosql')->client()->xClaim($stream::STREAM, $stream::GROUP, 'interrupted', 0, [$delivery['id']], ['IDLE' => 100000, 'JUSTID']);
        $this->assertSame(1, $stream->consume('recovery'));
        $this->assertSame(['article-a' => 5.0], app(Trending::class)->top(synthetic: true));
        $this->assertSame(['stream' => 0, 'pending' => 0, 'dead_letters' => 0], $stream->lengths());
    }

    public function test_rebuild_uses_versioned_synthetic_source_and_is_repeatable(): void
    {
        $source = app(InteractionReplaySource::class);
        $trending = app(Trending::class);
        $this->assertSame(5, $trending->rebuild($source, synthetic: true));
        $this->assertSame(['article-a' => 7.0, 'article-b' => 6.0], $trending->top(synthetic: true));
        $this->assertSame(5, $trending->rebuild($source, synthetic: true));
        $this->assertSame(['article-a' => 7.0, 'article-b' => 6.0], $trending->top(synthetic: true));
        // One Redis key is the complete projection eviction unit.
        Redis::connection('nosql')->del(Trending::DEMO_KEY);
        $this->assertSame([], $trending->top(synthetic: true));
        $trending->rebuild($source, synthetic: true);
        $this->assertSame(['article-a' => 7.0, 'article-b' => 6.0], $trending->top(synthetic: true));
    }

    public function test_inspection_and_rebuild_commands_are_readable(): void
    {
        $this->artisan('interactions', ['action' => 'rebuild', '--synthetic' => true])
            ->expectsOutputToContain('Rebuilt 5 synthetic events')->assertExitCode(0);
        $this->artisan('interactions', ['action' => 'top', '--synthetic' => true])->expectsOutputToContain('article-a')->assertExitCode(0);
        $this->artisan('interactions', ['action' => 'inspect'])->expectsOutputToContain('"pending": 0')->assertExitCode(0);
    }

    public function test_guarded_flush_preserves_durable_entities_and_requires_relogin(): void
    {
        config(['session.driver' => 'redis', 'session.connection' => 'default', 'cache.default' => 'redis']);
        $user = User::factory()->create();
        $article = Article::create(['title' => 'Synthetic flush fixture', 'slug' => 'flush-fixture', 'original_url' => 'https://example.test/flush']);
        Story::create(['title' => 'Synthetic story', 'status' => 'pending']);
        Bookmark::create(['user_id' => $user->id, 'article_id' => $article->id]);
        DB::connection('mongodb')->table('subscriptions')->insert(['user_id' => (string) $user->id, 'type' => 'pro', 'stripe_subscription_id' => 'synthetic-sub-15', 'status' => 'active']);
        $counts = fn () => [User::count(), Article::count(), Story::count(), Bookmark::count(), DB::connection('mongodb')->table('subscriptions')->count()];
        $before = $counts();
        $this->assertSame([1, 1, 1, 1, 1], $before);
        $sync = function ($response) {
            foreach ($response->headers->getCookies() as $cookie) {
                $this->withUnencryptedCookie($cookie->getName(), (string) $cookie->getValue());
            }
        };
        $sync($this->get('/login')->assertOk());
        $credentials = ['email' => $user->email, 'password' => 'password'];
        $sync($this->post('/login', $credentials)->assertRedirect('/dashboard'));
        $this->assertAuthenticated();
        $sessionId = $this->app['session']->getId();
        $this->assertNotEmpty(Redis::connection('default')->keys('*'.$sessionId.'*'));
        Cache::put('gate15', 'cached', 60);
        $this->assertSame('cached', Cache::get('gate15'));
        $trending = app(Trending::class);
        $trending->rebuild(app(InteractionReplaySource::class), synthetic: true);
        $top = $trending->top(synthetic: true);
        $stream = new InteractionStream;
        $stream->publish($this->event());
        $this->assertSame(1, $stream->lengths()['stream']);
        $this->flushTestDatabase(Redis::connection('nosql'));
        $this->assertSame($before, $counts());
        $this->assertSame([], $trending->top(synthetic: true));
        $this->assertSame([], Redis::connection('default')->keys('*'.$sessionId.'*'));
        $this->assertNull(Cache::get('gate15'));
        $this->assertSame(0, $stream->lengths()['stream']);
        // Simulate a new HTTP request/process, without a cached authenticated guard.
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $sync($this->get('/dashboard')->assertRedirect('/login'));
        $this->assertGuest();
        $sync($this->post('/login', $credentials)->assertRedirect('/dashboard'));
        $this->assertAuthenticated();
        $this->get('/dashboard')->assertRedirect('/');
        $trending->rebuild(app(InteractionReplaySource::class), synthetic: true);
        $this->assertSame($top, $trending->top(synthetic: true));
        $this->assertSame($before, $counts());
    }

    public function test_native_prefixes_cover_streams_sorted_sets_and_lua_keys(): void
    {
        $stream = new InteractionStream;
        $stream->publish($this->event('boost'));
        $redis = Redis::connection('nosql')->client();
        $prefix = $redis->getOption(\Redis::OPT_PREFIX);
        $this->assertNotEmpty($prefix);
        // rawCommand deliberately does not prefix; native typed commands and EVAL KEYS do.
        $this->assertSame(1, $redis->rawCommand('XLEN', $prefix.$stream::STREAM));
        $this->assertSame(0, $redis->rawCommand('XLEN', $stream::STREAM));
        $this->assertSame(1, $stream->consume('prefix-proof'));
        $this->assertSame('5', (string) $redis->rawCommand('ZSCORE', $prefix.Trending::DEMO_KEY, 'article:article-a'));
        $this->assertFalse($redis->rawCommand('ZSCORE', Trending::DEMO_KEY, 'article:article-a'));
        $this->assertSame(['article-a' => 5.0], app(Trending::class)->top(synthetic: true));
    }

    private function agePending(InteractionStream $stream): void
    {
        foreach ($stream->pending() as [$id, $owner]) {
            Redis::connection('nosql')->client()->xClaim($stream::STREAM, $stream::GROUP, $owner, 0, [$id], ['IDLE' => 100000, 'JUSTID']);
        }
    }

    public function test_fresh_entry_without_event_is_dead_lettered_once_with_original_id(): void
    {
        $stream = new InteractionStream;
        $id = Redis::connection('nosql')->client()->xAdd($stream::STREAM, '*', ['other' => 'malformed']);
        $this->assertSame(0, $stream->consume('missing', handler: fn () => $this->fail('Missing event must not reach handler')));
        $this->assertSame(['stream' => 0, 'pending' => 0, 'dead_letters' => 1], $stream->lengths());
        $dead = array_values($stream->deadLetters())[0];
        $this->assertSame($id, $dead['original_id']);
        $this->assertSame('', $dead['event']);
        $this->assertSame('1', $dead['attempts']);
        $this->assertStringContainsString('payload missing', $dead['last_error']);
        $this->assertSame(0, $stream->consume('again'));
        $this->assertCount(1, $stream->deadLetters());
    }

    public function test_read_group_error_is_distinct_from_an_empty_read(): void
    {
        $stream = new InteractionStream;
        $this->assertSame(0, Artisan::call('interactions', ['action' => 'consume']));
        $this->assertStringContainsString('Processed 0 deliveries', Artisan::output());
        $manager = Redis::getFacadeRoot();
        $original = $manager->connection('nosql')->client();
        $client = new class extends \Redis
        {
            public mixed $readResult = null;

            public function xReadGroup(string $group, string $consumer, array $streams, int $count = 1, int $block = 1): \Redis|array|bool
            {
                // Simulate a real key-type race AFTER XPENDING, on guarded DB15 only.
                if ($this->getDbNum() !== 15) {
                    throw new \RuntimeException('Read failure injection requires DB15');
                }
                $this->set(InteractionStream::STREAM, 'wrong-type');

                return $this->readResult = parent::xReadGroup($group, $consumer, $streams, $count, $block);
            }
        };
        $config = config('database.redis.nosql');
        $client->connect($config['host'], (int) $config['port']);
        if (! empty($config['password'])) {
            $client->auth(! empty($config['username']) ? [$config['username'], $config['password']] : $config['password']);
        }
        $client->select(15);
        $this->assertSame(15, $client->getDbNum());
        $client->setOption(\Redis::OPT_PREFIX, $original->getOption(\Redis::OPT_PREFIX));
        Redis::shouldReceive('connection')->with('nosql')->andReturn(new PhpRedisConnection($client));
        try {
            $this->assertSame(1, Artisan::call('interactions', ['action' => 'consume']));
            $this->assertFalse($client->readResult, 'Real phpredis error must return false for this regression');
            $output = Artisan::output();
            $this->assertStringContainsString('XREADGROUP', $output);
            $this->assertStringContainsString('WRONGTYPE', $output);
        } finally {
            Redis::swap($manager);
            $client->close();
        }
    }

    public function test_retry_error_recording_failure_stays_pending_and_bounded(): void
    {
        $stream = new InteractionStream;
        $id = $stream->publish($this->event('poison'));
        Redis::connection('nosql')->set($stream::ERRORS, 'wrong-type');
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->agePending($stream);
            $this->assertSame(1, Artisan::call('interactions', ['action' => 'consume']));
            $output = Artisan::output();
            $this->assertStringContainsString($attempt < 5 ? 'HSET' : 'dead-letter', $output);
            $this->assertStringContainsString('WRONGTYPE', $output);
            $this->assertSame(['stream' => 1, 'pending' => 1, 'dead_letters' => 0], $stream->lengths());
            $this->assertSame($attempt, $stream->pending()[0][3]);
            $this->assertSame($id, $stream->pending()[0][0]);
        }
        $this->agePending($stream);
        $this->assertSame(1, Artisan::call('interactions', ['action' => 'consume']));
        $this->assertSame(5, $stream->pending()[0][3]);
        Redis::connection('nosql')->del($stream::ERRORS);
        $this->agePending($stream);
        $this->assertSame(0, $stream->consume('repaired', handler: fn () => $this->fail('Sixth handler invocation forbidden')));
        $this->assertSame(['stream' => 0, 'pending' => 0, 'dead_letters' => 1], $stream->lengths());
        $dead = array_values($stream->deadLetters())[0];
        $this->assertSame($id, $dead['original_id']);
        $this->assertSame('5', $dead['attempts']);
    }

    public function test_dlq_inspection_command_proof_and_replay(): void
    {
        $stream = new InteractionStream;
        $event = $this->event('boost');
        $stream->publish($event);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->agePending($stream);
            $this->assertSame(1, $stream->consume('failing', handler: function () {
                throw new \RuntimeException('Synthetic projection outage');
            }));
        }
        $this->assertSame(['stream' => 0, 'pending' => 0, 'dead_letters' => 1], $stream->lengths());
        Artisan::call('interactions', ['action' => 'inspect']);
        $output = Artisan::output();
        $this->assertStringContainsString('"dead_letters": 1', $output);
        $this->assertStringContainsString('"attempts": "5"', $output);
        $this->assertStringContainsString('Synthetic projection outage', $output);
        if (getenv('GATE15_DLQ_PROOF')) {
            fwrite(STDOUT, "DB15 inspection command proof:\n".$output);
        }
        $id = array_key_first($stream->deadLetters());
        $this->artisan('interactions', ['action' => 'replay', '--id' => $id])->expectsOutputToContain('Requeued')->assertExitCode(0);
        $this->assertSame(1, $stream->consume('replay'));
        $this->assertSame(['article-a' => 5.0], app(Trending::class)->top(synthetic: true));
        $stream->publish($event);
        $stream->consume('duplicate');
        $this->assertSame(['article-a' => 5.0], app(Trending::class)->top(synthetic: true));
        $this->assertSame(['stream' => 0, 'pending' => 0, 'dead_letters' => 0], $stream->lengths());
    }

    public function test_interrupted_final_delivery_is_terminal_without_a_sixth_handler_call(): void
    {
        $stream = new InteractionStream;
        $stream->publish($this->event());
        for ($i = 1; $i <= 5; $i++) {
            $this->agePending($stream);
            $delivery = $stream->receive('crashing');
            $this->assertSame($i, $delivery['attempts']);
        }
        $this->agePending($stream);
        $this->assertSame(0, $stream->consume('recover', handler: fn () => $this->fail('Sixth attempt forbidden')));
        $this->assertSame(['stream' => 0, 'pending' => 0, 'dead_letters' => 1], $stream->lengths());
        $this->assertSame('5', array_values($stream->deadLetters())[0]['attempts']);
    }

    public function test_transient_failure_recovers_and_lost_stream_group_is_recreated(): void
    {
        $stream = new InteractionStream;
        $event = $this->event('boost');
        $stream->publish($event);
        $stream->consume('outage', handler: function () {
            throw new \RuntimeException('Temporary outage');
        });
        $this->assertSame([], app(Trending::class)->top(synthetic: true));
        $this->agePending($stream);
        $stream->consume('recovered');
        $this->assertSame(['article-a' => 5.0], app(Trending::class)->top(synthetic: true));
        Redis::connection('nosql')->del($stream::STREAM);
        $stream->publish($event);
        $this->assertSame(1, $stream->consume('new-group'));
        $this->assertSame(['article-a' => 5.0], app(Trending::class)->top(synthetic: true));
        $this->assertSame(0, $stream->lengths()['pending']);
    }

    public function test_invalid_contracts_are_rejected_without_scoring(): void
    {
        $base = $this->event();
        foreach ([['event_id' => (string) Str::uuid()], ['type' => 'other'], ['aggregate_id' => 'invalid'], ['aggregate_version' => 0], ['aggregate_version' => '1'], ['occurred_at' => 'yesterday'], ['occurred_at' => '2026-11-15 12:00:00'], ['article_id' => '']] as $change) {
            try {
                app(Trending::class)->apply(InteractionEvent::fromArray(array_replace($base, $change)));
                $this->fail('Invalid event accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertSame([], app(Trending::class)->top(synthetic: true));
    }

    public function test_failed_rebuild_preserves_previous_ranking_and_empty_rebuild_clears_it(): void
    {
        $trending = app(Trending::class);
        $trending->apply(InteractionEvent::fromArray($this->event('boost')));
        $source = new class implements InteractionReplaySource
        {
            public function events(): iterable
            {
                throw new \RuntimeException('Replay unavailable');
                yield;
            }
        };
        try {
            $trending->rebuild($source, synthetic: true);
            $this->fail('Failed replay should propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('Replay unavailable', $e->getMessage());
        }
        $this->assertSame(['article-a' => 5.0], $trending->top(synthetic: true));
        $empty = new class implements InteractionReplaySource
        {
            public function events(): iterable
            {
                return [];
            }
        };
        $this->assertSame(0, $trending->rebuild($empty, synthetic: true));
        $this->assertSame([], $trending->top(synthetic: true));
    }

    public function test_evicted_staging_ranking_cannot_replace_the_current_projection(): void
    {
        $trending = app(Trending::class);
        $event = InteractionEvent::fromArray($this->event('boost'));
        $trending->apply($event);
        $source = new class($event) implements InteractionReplaySource
        {
            public function __construct(private InteractionEvent $event) {}

            public function events(): iterable
            {
                yield $this->event;
                $redis = Redis::connection('nosql');
                $staging = $redis->keys(Trending::DEMO_KEY.':rebuild:*');
                // KEYS returns prefixed names, so strip the prefix for typed DEL.
                $prefix = $redis->client()->getOption(\Redis::OPT_PREFIX);
                $redis->del(substr($staging[0], strlen($prefix)));
            }
        };

        try {
            $trending->rebuild($source, synthetic: true);
            $this->fail('Evicted staging must fail promotion');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Trending rebuild staging key lost', $e->getMessage());
        }
        $this->assertSame(['article-a' => 5.0], $trending->top(synthetic: true));
    }

    public function test_installed_phpredis_eval_error_contract(): void
    {
        $connection = Redis::connection('nosql');
        $client = $connection->client();
        $client->clearLastError();
        $connection->set('driver-error-probe', 'wrong-type');
        $this->assertFalse($connection->eval("return redis.call('ZADD', KEYS[1], 1, 'member')", 1, 'driver-error-probe'));
        $this->assertStringContainsString('WRONGTYPE', $client->getLastError());
        $this->assertTrue($client->clearLastError());
        $this->assertNull($client->getLastError());
        $this->assertSame(0, $connection->eval('return 0', 0));
        $this->assertSame(1, $connection->eval('return 1', 0));
        $options = array_filter((new \ReflectionClass(\Redis::class))->getConstants(), fn ($value, $name) => str_starts_with($name, 'OPT_') && (str_contains($name, 'THROW') || str_contains($name, 'ERROR')), ARRAY_FILTER_USE_BOTH);
        $this->assertSame([], $options, 'Recheck driver error options on extension upgrade');
    }

    public function test_real_projection_error_stays_pending_then_reaches_dlq(): void
    {
        $stream = new InteractionStream;
        Redis::connection('nosql')->set(Trending::DEMO_KEY, 'wrong-type');
        $stream->publish($this->event());
        $this->assertSame(1, $stream->consume('error'));
        $this->assertSame(['stream' => 1, 'pending' => 1, 'dead_letters' => 0], $stream->lengths());
        for ($attempt = 2; $attempt <= 5; $attempt++) {
            $this->agePending($stream);
            $this->assertSame(1, $stream->consume('error'));
        }
        $this->assertSame(['stream' => 0, 'pending' => 0, 'dead_letters' => 1], $stream->lengths());
        $error = array_values($stream->deadLetters())[0]['last_error'];
        $this->assertStringContainsString('WRONGTYPE', $error);
        $this->assertStringContainsString('Trending apply', $error);
    }

    public function test_failed_dlq_write_reports_failure_and_preserves_terminal_input(): void
    {
        $stream = new InteractionStream;
        $stream->publish($this->event('poison'));
        for ($attempt = 1; $attempt < 5; $attempt++) {
            $this->agePending($stream);
            $stream->consume('failing');
        }
        Redis::connection('nosql')->set($stream::DLQ, 'wrong-type');
        $this->agePending($stream);
        $this->assertSame(1, Artisan::call('interactions', ['action' => 'consume']));
        $output = Artisan::output();
        $this->assertStringContainsString('dead-letter', $output);
        $this->assertStringContainsString('WRONGTYPE', $output);
        $this->assertSame('5', (string) $stream->pending()[0][3]);
        $this->assertSame(1, Redis::connection('nosql')->xlen($stream::STREAM));
        Redis::connection('nosql')->del($stream::DLQ);
        $this->agePending($stream);
        $this->assertSame(0, $stream->consume('recovered'));
        $this->assertSame(['stream' => 0, 'pending' => 0, 'dead_letters' => 1], $stream->lengths());
    }

    public static function failedRebuildCommands(): array
    {
        return [['zadd'], ['eval'], ['rename'], ['del']];
    }

    #[DataProvider('failedRebuildCommands')]
    public function test_rebuild_command_results_preserve_old_ranking_or_confirm_promotion(string $command): void
    {
        $trending = app(Trending::class);
        $trending->apply(InteractionEvent::fromArray($this->event('boost')));
        Artisan::call('interactions', ['action' => 'top', '--synthetic' => true]);
        $manager = Redis::getFacadeRoot();
        $connection = $manager->connection('nosql');
        // Inject a false command reply at the database boundary, with other calls real.
        $proxy = new class($connection->client(), $command) extends PhpRedisConnection
        {
            public function __construct($client, private string $failure)
            {
                parent::__construct($client);
            }

            public function command($method, array $parameters = [])
            {
                $operation = $method === 'eval' && str_contains($parameters[0], "redis.call('RENAME'") ? 'rename' : $method;

                return $operation === $this->failure ? false : parent::command($method, $parameters);
            }
        };
        Redis::shouldReceive('connection')->with('nosql')->andReturn($proxy);
        try {
            $exit = Artisan::call('interactions', ['action' => 'rebuild', '--synthetic' => true]);
            $this->assertSame($command === 'del' ? 0 : 1, $exit);
            $output = Artisan::output();
            if ($command === 'del') {
                // RENAME already removed staging; no post-promotion DEL is needed.
                $this->assertStringContainsString('Rebuilt 5 synthetic events', $output);
            } else {
                $this->assertStringContainsString($command === 'eval' ? 'Trending apply' : 'Trending rebuild '.$command, $output);
                $this->assertStringNotContainsString('Rebuilt', $output);
            }
        } finally {
            Redis::swap($manager);
        }
        $this->assertSame($command === 'del' ? ['article-a' => 7.0, 'article-b' => 6.0] : ['article-a' => 5.0], $trending->top(synthetic: true));
        $this->assertSame([], $connection->keys(Trending::DEMO_KEY.':rebuild:*'));
    }

    public function test_ack_cleanup_error_does_not_lose_pending_input(): void
    {
        $stream = new InteractionStream;
        $id = $stream->publish($this->event());
        $stream->receive('worker');
        Redis::connection('nosql')->set($stream::ERRORS, 'wrong-type');
        try {
            $stream->acknowledge($id);
            $this->fail('ACK must report cleanup failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('acknowledge', $e->getMessage());
        }
        $this->assertSame(['stream' => 1, 'pending' => 1, 'dead_letters' => 0], $stream->lengths());
        Redis::connection('nosql')->del($stream::ERRORS);
        $stream->acknowledge($id);
        $this->assertSame(0, $stream->lengths()['pending']);
    }

    public function test_replay_redis_error_is_contextual_and_preserves_dead_letter(): void
    {
        $stream = new InteractionStream;
        $redis = Redis::connection('nosql');
        $id = $redis->client()->xadd($stream::DLQ, '*', ['event' => json_encode($this->event())]);
        $redis->set($stream::STREAM, 'wrong-type');
        try {
            $stream->replay($id);
            $this->fail('Replay must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('replay', $e->getMessage());
            $this->assertStringContainsString('WRONGTYPE', $e->getMessage());
        }
        $this->assertArrayHasKey($id, $stream->deadLetters());
        $redis->del($stream::STREAM);
        $this->assertNotEmpty($stream->replay($id));
        $this->assertSame([], $stream->deadLetters());
    }

    public function test_receive_recovers_eligible_pending_beyond_first_page(): void
    {
        $stream = new InteractionStream(retryMilliseconds: 100000, pendingPageSize: 2);
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $stream->publish($this->event());
            $this->assertSame($ids[$i], $stream->receive('original')['id']);
        }
        Redis::connection('nosql')->client()->xClaim($stream::STREAM, $stream::GROUP, 'original', 0, [$ids[2]], ['IDLE' => 1000000, 'JUSTID']);
        $delivery = $stream->receive('recovery');
        $this->assertSame($ids[2], $delivery['id']);
        $this->assertSame(2, $delivery['attempts']);
        $this->assertNull($stream->receive('recovery'));
        $this->assertSame(1, $stream->pending()[0][3]);
    }

    public function test_uuid_case_redelivery_and_aggregate_versions_share_canonical_identity(): void
    {
        $stream = new InteractionStream;
        $event = $this->event('boost');
        $event['event_id'] = '019abced-abcd-7000-8000-000000000001';
        $event['aggregate_id'] = '019abced-abcd-7000-8000-000000000002';
        $upper = array_replace($event, ['event_id' => strtoupper($event['event_id']), 'aggregate_id' => strtoupper($event['aggregate_id'])]);
        $stream->publish($upper);
        $stream->consume('upper');
        $stream->publish($event);
        $stream->consume('lower');
        $this->assertSame(['article-a' => 5.0], app(Trending::class)->top(synthetic: true));
        $upper['event_id'] = (string) Str::uuid7();
        $stream->publish($upper);
        $stream->consume('same-version');
        $this->assertSame(['article-a' => 5.0], app(Trending::class)->top(synthetic: true));
        $canonical = InteractionEvent::fromArray(array_replace($upper, ['event_id' => strtoupper($event['event_id'])]));
        $this->assertSame($event['event_id'], $canonical->data['event_id']);
        $this->assertSame($event['aggregate_id'], $canonical->data['aggregate_id']);
    }

    public static function interruptedMissingPayloads(): array
    {
        return [[1], [5]];
    }

    #[DataProvider('interruptedMissingPayloads')]
    public function test_missing_pending_payload_is_recorded_and_removed_without_handler(int $attempts): void
    {
        $stream = new InteractionStream;
        $id = $stream->publish($this->event());
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->agePending($stream);
            $stream->receive('interrupted');
        }
        $this->agePending($stream);
        Redis::connection('nosql')->client()->xdel($stream::STREAM, [$id]);
        $this->assertSame(0, $stream->consume('recovery', handler: fn () => $this->fail('Missing payload must not reach handler')));
        $this->assertSame(['stream' => 0, 'pending' => 0, 'dead_letters' => 1], $stream->lengths());
        $dead = array_values($stream->deadLetters())[0];
        $this->assertSame($id, $dead['original_id']);
        $this->assertSame((string) $attempts, $dead['attempts']);
        $this->assertSame('', $dead['event']);
        $this->assertStringContainsString('payload missing', $dead['last_error']);
        $this->assertSame(0, $stream->consume('again'));
        $this->assertCount(1, $stream->deadLetters());
    }

    public function test_dlq_cleanup_type_error_cannot_partially_remove_input(): void
    {
        $stream = new InteractionStream;
        $stream->publish($this->event());
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->agePending($stream);
            $stream->receive('interrupted');
        }
        Redis::connection('nosql')->set($stream::ERRORS, 'wrong-type');
        $this->agePending($stream);
        try {
            $stream->consume('terminal');
            $this->fail('Terminal cleanup must report WRONGTYPE');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('WRONGTYPE', $e->getMessage());
        }
        $this->assertSame(['stream' => 1, 'pending' => 1, 'dead_letters' => 0], $stream->lengths());
        Redis::connection('nosql')->del($stream::ERRORS);
        $this->agePending($stream);
        $this->assertSame(0, $stream->consume('terminal'));
        $this->assertSame(['stream' => 0, 'pending' => 0, 'dead_letters' => 1], $stream->lengths());
    }
}
