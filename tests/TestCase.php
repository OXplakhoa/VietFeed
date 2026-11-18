<?php

namespace Tests;

use App\ReadingPass\ReadingPassHistory;
use App\ReadingPass\ReadingPassQuota;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        if (file_exists(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new \RuntimeException('Remove cached config before database tests.');
        }
        $app = parent::createApplication();
        // This runs before RefreshDatabase migrates and before any test mutates stores.
        $this->assertSame('vietfeed_test', $app['config']->get('database.connections.mongodb.database'));
        $this->assertSame('sqlite', $app['config']->get('database.default'));
        $this->assertSame(':memory:', $app['config']->get('database.connections.sqlite.database'));
        foreach (['default', 'cache', 'nosql'] as $name) {
            $app['config']->set("database.redis.$name.url", null);
            $app['config']->set("database.redis.$name.database", 15);
        }

        $this->assertSame(15, $app['redis']->connection('nosql')->client()->getDbNum());

        return $app;
    }

    // Gate 3: User/Article/Story docs live on Mongo (RefreshDatabase only wraps the
    // default SQL connection), so wipe those collections after each test for isolation.
    // Compose Mongo must be up for the suite (documented prerequisite).
    protected function tearDown(): void
    {
        $this->assertSame('vietfeed_test', DB::connection('mongodb')->getDatabaseName());
        // Gate 9b and 9.5: moderation documents, comments, bookmarks, boosts and unlocks also live on MongoDB.
        foreach (['categories', 'sources', 'users', 'articles', 'stories', 'subscriptions', 'processed_stripe_events', 'reports', 'sanctions', 'moderation_cases', 'comments', 'reports_duplicates', 'bookmarks', 'boosts', 'article_unlocks'] as $collection) {
            DB::connection('mongodb')->table($collection)->delete();
        }
        // Gate 9.5 slice 3b: Reading Pass windows and history stream live in Redis DB15. A new generation
        // invalidates every window (fixed guest session ids recur across tests and suite runs).
        $redis = Redis::connection('nosql')->client();
        $this->assertSame(15, $redis->getDbNum());
        $redis->set(ReadingPassQuota::GEN, (string) Str::uuid7());
        $redis->del([ReadingPassHistory::STREAM, ReadingPassHistory::DLQ, ReadingPassHistory::ERRORS]);

        parent::tearDown();
    }
}
