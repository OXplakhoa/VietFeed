<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

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

        return $app;
    }

    // Gate 3: User/Article/Story docs live on Mongo (RefreshDatabase only wraps the
    // default SQL connection), so wipe those collections after each test for isolation.
    // Compose Mongo must be up for the suite (documented prerequisite).
    protected function tearDown(): void
    {
        $this->assertSame('vietfeed_test', DB::connection('mongodb')->getDatabaseName());
        foreach (['users', 'articles', 'stories', 'subscriptions', 'processed_stripe_events'] as $collection) {
            DB::connection('mongodb')->table($collection)->delete();
        }

        parent::tearDown();
    }
}
