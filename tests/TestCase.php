<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    // Gate 3: User/Article/Story docs live on Mongo (RefreshDatabase only wraps the
    // default SQL connection), so wipe those collections after each test for isolation.
    // Compose Mongo must be up for the suite (documented prerequisite).
    protected function tearDown(): void
    {
        foreach (['users', 'articles', 'stories'] as $collection) {
            DB::connection('mongodb')->table($collection)->delete();
        }

        parent::tearDown();
    }
}
