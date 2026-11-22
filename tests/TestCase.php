<?php

namespace Tests;

use App\ReadingPass\ReadingPassHistory;
use App\ReadingPass\ReadingPassQuota;
use App\SourceHistory\SourceFetchHistory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use MongoDB\Laravel\Connection as MongoConnection;
use Tests\Concerns\GuardsRedisTestDatabase;

abstract class TestCase extends BaseTestCase
{
    use GuardsRedisTestDatabase;

    /** Gate 9.5 slice 5: Mongo index migrations rerun on `vietfeed_test` once per PHPUnit process. */
    private static bool $mongoMigrated = false;

    /** @var list<string> queries that reached a non-Mongo database connection during the current test */
    protected array $sqlQueries = [];

    public function createApplication()
    {
        if (file_exists(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new \RuntimeException('Remove cached config before database tests.');
        }
        $app = parent::createApplication();
        // This runs before the Mongo test database is rebuilt and before any test mutates stores.
        $this->assertSame('vietfeed_test', $app['config']->get('database.connections.mongodb.database'));
        // Gate 9.5 slice 5: MongoDB is the only database connection; no SQL driver is configured.
        $this->assertSame('mongodb', $app['config']->get('database.default'));
        $this->assertSame(['mongodb'], array_keys($app['config']->get('database.connections')));
        foreach (['default', 'cache', 'nosql'] as $name) {
            $app['config']->set("database.redis.$name.url", null);
            $app['config']->set("database.redis.$name.database", 15);
        }

        $this->assertSame(15, $app['redis']->connection('nosql')->client()->getDbNum());

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('vietfeed_test', DB::connection('mongodb')->getDatabaseName());
        if (! self::$mongoMigrated) {
            // Like the former RefreshDatabase run, every PHPUnit process reruns all (idempotent) Mongo index migrations:
            // forget the `vietfeed_test` migration records, then migrate through the Laravel repository on the default
            // mongodb connection. Collections are not dropped: creating every index from scratch costs ~20 s on WSL,
            // and documents are wiped per test in tearDown (the clean-reset proof starts from an empty database).
            DB::connection('mongodb')->getCollection('migrations')->drop();
            $this->artisan('migrate')->assertSuccessful();
            // Start from empty test collections even if a previous process (or a manual seed) left documents behind.
            $this->wipeMongoCollections();
            self::$mongoMigrated = true;
        }
        // "No SQL during the suite": any query on a non-Mongo connection is recorded and fails the test in tearDown.
        $this->sqlQueries = [];
        $this->app['events']->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if (! $query->connection instanceof MongoConnection) {
                $this->sqlQueries[] = $query->connectionName.': '.$query->sql;
            }
        });
    }

    private function wipeMongoCollections(): void
    {
        // Gate 9b and 9.5: moderation documents, comments, bookmarks, boosts and unlocks also live on MongoDB.
        foreach (['categories', 'sources', 'users', 'articles', 'stories', 'story_briefs', 'subscriptions', 'processed_stripe_events', 'reports', 'sanctions', 'moderation_cases', 'comments', 'reports_duplicates', 'bookmarks', 'boosts', 'article_unlocks', 'failed_jobs', 'job_batches'] as $collection) {
            DB::connection('mongodb')->table($collection)->delete();
        }
    }

    /** @return list<string> SQL queries and non-Mongo database connections seen since setUp (empty when clean) */
    protected function sqlViolations(): array
    {
        $violations = $this->sqlQueries;
        foreach (DB::getConnections() as $name => $connection) {
            if (! $connection instanceof MongoConnection) {
                $violations[] = "connection [$name] ({$connection->getDriverName()}) was resolved";
            }
        }

        return $violations;
    }

    // Gate 3: User/Article/Story docs live on Mongo, so wipe those collections after each test for isolation.
    // Compose Mongo must be up for the suite (documented prerequisite).
    protected function tearDown(): void
    {
        $this->assertSame('vietfeed_test', DB::connection('mongodb')->getDatabaseName());
        // Gate 9.5 slice 5: no test may resolve a SQL connection or run a SQL query. Collected before the wipe and
        // asserted after it, so a violation never leaks state into the next test.
        $violations = $this->sqlViolations();
        $this->wipeMongoCollections();
        // Gate 9.5 slice 3b: Reading Pass windows and history stream live in Redis DB15. A new generation
        // invalidates every window (fixed guest session ids recur across tests and suite runs).
        // REVIEW-1 m3: cache-store keys (reset tokens, throttles, rate limits) must not leak between tests. The cache,
        // default and nosql connections all point at DB15 in tests, so this guarded flush empties the test DB.
        $this->flushTestDatabase(Redis::connection('cache'));
        $redis = Redis::connection('nosql')->client();
        $this->assertSame(15, $redis->getDbNum());
        $redis->set(ReadingPassQuota::GEN, (string) Str::uuid7());
        $redis->del([ReadingPassHistory::STREAM, ReadingPassHistory::DLQ, ReadingPassHistory::ERRORS]);
        $redis->del([SourceFetchHistory::STREAM, SourceFetchHistory::DLQ, SourceFetchHistory::ERRORS]);

        parent::tearDown();

        $this->assertSame([], $violations, 'A SQL database connection or query was used during the test.');
    }
}
