<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    // Gate 3: User docs live on Mongo (RefreshDatabase only wraps the default
    // SQL connection), so wipe the users collection after each test for isolation.
    // Compose Mongo must be up for the suite (documented prerequisite).
    protected function tearDown(): void
    {
        DB::connection('mongodb')->table('users')->delete();

        parent::tearDown();
    }
}
