<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Doubles\MongoTestUser;
use Tests\TestCase;

// Gate 3 T1: one Auth surface runs against the mongo double — credential check
// through the real session guard + Eloquent provider with the model pointed at
// mongodb (config swap, no prod model change; T2 moves the prod model).
// Run: vendor/bin/phpunit tests/Integration/MongoAuthDoubleTest.php
class MongoAuthDoubleTest extends TestCase
{
    public function test_login_attempt_against_mongo_user(): void
    {
        config()->set('auth.providers.users.model', MongoTestUser::class);

        DB::connection('mongodb')->table('users')->where('email', 'mongo-login@example.com')->delete();
        MongoTestUser::create([
            'name' => 'Mongo Login',
            'email' => 'mongo-login@example.com',
            'password' => 'secret123',
        ]);

        $this->assertTrue(Auth::attempt(['email' => 'mongo-login@example.com', 'password' => 'secret123']));
        $this->assertAuthenticated();
        $this->assertTrue(
            DB::connection('mongodb')->table('users')->where('email', 'mongo-login@example.com')->exists(),
            'user doc lives in the mongo test DB, not sqlite',
        );

        DB::connection('mongodb')->table('users')->where('email', 'mongo-login@example.com')->delete();
    }
}
