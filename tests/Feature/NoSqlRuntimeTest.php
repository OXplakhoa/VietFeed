<?php

namespace Tests\Feature;

use App\Console\DestructiveCommandGuard;
use App\Models\Category;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\Concerns\GuardsRedisTestDatabase;
use Tests\TestCase;

/**
 * Gate 9.5 slice 5: the application runs without any SQL driver. Isolated stores only: Mongo `vietfeed_test`,
 * Redis DB15 (guarded), no Cassandra. The suite-wide "no SQL" guard lives in Tests\TestCase.
 */
class NoSqlRuntimeTest extends TestCase
{
    use GuardsRedisTestDatabase;

    protected function tearDown(): void
    {
        try {
            foreach (['default', 'cache'] as $name) {
                $this->flushTestDatabase(Redis::connection($name));
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_only_mongodb_is_configured_and_runtime_stores_never_point_at_sql(): void
    {
        $this->assertSame('mongodb', config('database.default'));
        $this->assertSame(['mongodb'], array_keys(config('database.connections')));
        $this->assertArrayNotHasKey('database', config('cache.stores'));
        $this->assertArrayNotHasKey('database', config('queue.connections'));
        $this->assertSame(['redis', 'deferred'], config('queue.connections.failover.connections'));
        $this->assertSame(['redis', 'array'], config('cache.stores.failover.stores'));
        $this->assertSame(['database-uuids', 'mongodb', 'failed_jobs'], [config('queue.failed.driver'), config('queue.failed.database'), config('queue.failed.table')]);
        $this->assertSame(['mongodb', 'mongodb'], [config('queue.batching.driver'), config('queue.batching.database')]);
        $this->assertSame(['cache', 'redis', 60, 60], [config('auth.passwords.users.driver'), config('auth.passwords.users.store'),
            config('auth.passwords.users.expire'), config('auth.passwords.users.throttle')]);
        // A SQL connection name cannot even be resolved.
        $this->expectException(\InvalidArgumentException::class);
        DB::connection('sqlite');
    }

    public function test_migrations_are_recorded_in_the_mongodb_migrations_collection(): void
    {
        $files = collect(glob(database_path('migrations/*.php')))->map(fn ($path) => basename($path, '.php'))->sort()->values()->all();
        $this->assertCount(11, $files);
        $this->assertContains('2026_11_22_000001_create_mongodb_story_brief_indexes', $files);
        $this->assertContains('2026_11_23_000001_create_mongodb_authorization_audit_indexes', $files);
        $this->assertSame([], array_values(array_filter($files, fn ($name) => ! str_contains($name, '_mongodb_'))), 'only Mongo migrations remain');
        $this->assertSame($files, DB::connection('mongodb')->table('migrations')->orderBy('migration')->pluck('migration')->all());

        $this->artisan('migrate')->expectsOutputToContain('Nothing to migrate')->assertSuccessful();
        $this->artisan('migrate:status')->expectsOutputToContain('2026_11_18_300001_create_mongodb_queue_indexes')->assertSuccessful();
        $indexes = collect(iterator_to_array(DB::connection('mongodb')->getCollection('failed_jobs')->listIndexes()))->keyBy(fn ($index) => $index->getName());
        $this->assertTrue($indexes['uuid_1']->isUnique());
    }

    public function test_password_reset_tokens_live_in_redis_with_ttl_and_keep_the_throttle(): void
    {
        $this->isolateRedis(['cache']);
        $this->flushTestDatabase(Redis::connection('cache'));
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        $store = Cache::store('redis');
        $key = hash('sha256', $user->email);
        $this->assertIsArray($store->get($key), 'hashed token stored in Redis');
        $ttl = Redis::connection('cache')->ttl($store->getPrefix().$key);
        $this->assertGreaterThan(3500, $ttl);
        // Redis may round the remaining TTL up by one second right after the write.
        $this->assertLessThanOrEqual(3601, $ttl);

        // Throttled for 60 s: a second link is refused and the first token stays valid.
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasErrors('email');
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        Notification::assertSentToTimes($user, ResetPassword::class, 1);

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertSessionHasNoErrors()->assertRedirect(route('login'));
        $this->assertNull($store->get($key), 'a used token is deleted');
        $this->assertSame(Password::INVALID_TOKEN, Password::broker()->reset(
            ['token' => $token, 'email' => $user->email, 'password' => 'other-password-1', 'password_confirmation' => 'other-password-1'],
            fn () => $this->fail('a used token must not reset twice'),
        ));
    }

    public function test_failed_jobs_are_mongodb_documents_and_failed_and_retry_commands_work(): void
    {
        $this->isolateRedis(['default']);
        $this->flushTestDatabase(Redis::connection('default'));

        FailingProbeJob::dispatch()->onConnection('redis');
        $this->artisan('queue:work', ['connection' => 'redis', '--once' => true, '--tries' => 1])->assertSuccessful();

        $failed = DB::connection('mongodb')->table('failed_jobs')->sole();
        $this->assertSame(['redis', 'default'], [$failed->connection, $failed->queue]);
        $this->assertStringContainsString('probe failure', $failed->exception);
        $this->assertSame([$failed->uuid], app('queue.failer')->ids());
        $this->artisan('queue:failed')->expectsOutputToContain($failed->uuid)->assertSuccessful();

        $this->artisan('queue:retry', ['id' => [$failed->uuid]])->assertSuccessful();
        $this->assertSame([], app('queue.failer')->all());
        $this->assertSame(1, Queue::connection('redis')->size('default'));
    }

    /** REVIEW-1 MAJOR-1: outside `testing`, database-dropping commands are refused and nothing is dropped. */
    public function test_destructive_database_commands_are_refused_outside_testing_or_off_the_test_database(): void
    {
        $category = Category::create(['name' => 'Giữ lại', 'slug' => 'keep-me']);
        $mongo = DB::connection('mongodb');
        $collections = collect(iterator_to_array($mongo->getDatabase()->listCollectionNames()))->sort()->values()->all();
        $migrations = $mongo->table('migrations')->count();
        // Real CLI (Kernel::handle routes console events to the guard's message): a non-testing environment whose stores
        // are still the isolated test ones, so even a guard failure could only touch `vietfeed_test` / DB15.
        $env = ['APP_ENV' => 'local', 'MONGO_DB' => 'vietfeed_test', 'CASSANDRA_KEYSPACE' => 'vietfeed_test', 'REDIS_URL' => '',
            'REDIS_DB' => '15', 'REDIS_CACHE_DB' => '15', 'REDIS_NOSQL_DB' => '15'];
        foreach (DestructiveCommandGuard::COMMANDS as $command) {
            $process = new Process([PHP_BINARY, 'artisan', $command, '--force', '--no-ansi'], base_path(), $env);
            $process->run();
            $this->assertSame(1, $process->getExitCode(), $command.': '.$process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('Refused: '.$command.' would drop MongoDB data or indexes. Nothing was changed.', $process->getOutput());
            $this->assertStringContainsString('Safe reset: set MONGO_DB', $process->getOutput());
        }
        // In-process (Artisan::call): Laravel's Prohibitable flag alone refuses the command.
        $this->app['env'] = 'local';
        DestructiveCommandGuard::apply($this->app);
        try {
            foreach (DestructiveCommandGuard::COMMANDS as $command) {
                $this->artisan($command, ['--force' => true])->expectsOutputToContain('prohibited from running')->assertFailed();
            }
        } finally {
            $this->app['env'] = 'testing';
            DestructiveCommandGuard::apply($this->app);
        }
        // Fix round 2: `testing` alone is not enough; the configured Mongo database must be `vietfeed_test`. Simulated by
        // config only (a throwaway name, never the dev database); the resolved connection stays on `vietfeed_test`.
        config(['database.connections.mongodb.database' => 'vietfeed_not_test']);
        DestructiveCommandGuard::apply($this->app);
        try {
            $this->assertFalse(DestructiveCommandGuard::allowed($this->app));
            foreach (DestructiveCommandGuard::COMMANDS as $command) {
                $this->artisan($command, ['--force' => true])->expectsOutputToContain('prohibited from running')->assertFailed();
            }
        } finally {
            config(['database.connections.mongodb.database' => 'vietfeed_test']);
            DestructiveCommandGuard::apply($this->app);
        }
        $this->assertTrue(DestructiveCommandGuard::allowed($this->app));
        $this->assertSame('vietfeed_test', $mongo->getDatabaseName());
        $this->assertSame($collections, collect(iterator_to_array($mongo->getDatabase()->listCollectionNames()))->sort()->values()->all());
        $this->assertSame($migrations, $mongo->table('migrations')->count());
        $this->assertTrue($category->fresh()->exists);
        $indexes = collect(iterator_to_array($mongo->getCollection('categories')->listIndexes()))->map->getName()->all();
        $this->assertContains('slug_1', $indexes);
    }

    /** REVIEW-1 MINOR-1: every index migration's down() tolerates missing indexes; up() restores them. */
    public function test_index_migration_rollback_tolerates_missing_indexes_and_up_restores_them(): void
    {
        foreach (glob(database_path('migrations/*.php')) as $path) {
            $migration = require $path;
            $migration->down();
            $migration->down(); // second rollback: indexes already gone
            $migration->up();
        }
        $indexes = collect(iterator_to_array(DB::connection('mongodb')->getCollection('failed_jobs')->listIndexes()))->keyBy(fn ($index) => $index->getName());
        $this->assertTrue($indexes['uuid_1']->isUnique());
        $this->assertTrue(collect(iterator_to_array(DB::connection('mongodb')->getCollection('reports')->listIndexes()))
            ->contains(fn ($index) => $index->getName() === 'reporter_comment_unique' && $index->isUnique()));
    }

    public function test_the_no_sql_guard_records_sql_connections_and_queries(): void
    {
        $this->assertSame([], $this->sqlViolations());

        // A SQL-like connection without any PDO driver: the guard must flag both its resolution and its query.
        config(['database.connections.probe' => ['driver' => 'probe', 'name' => 'probe']]);
        DB::extend('probe', fn (array $config, string $name) => new Connection(fn () => throw new \LogicException('no PDO'), '', '', $config));
        event(new QueryExecuted('select 1', [], 0.1, DB::connection('probe')));

        $this->assertSame(['probe: select 1', 'connection [probe] (probe) was resolved'], $this->sqlViolations());

        DB::purge('probe');
        $this->sqlQueries = [];
        $this->assertSame([], $this->sqlViolations());
    }
}

class FailingProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        throw new \RuntimeException('probe failure');
    }
}
