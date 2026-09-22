<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Driver\Exception\BulkWriteException;
use Tests\TestCase;

// Gate 3 T1 double proof: the mongodb connection (database vietfeed_test under
// phpunit, see phpunit.xml MONGO_DB) round-trips documents and enforces the
// users.email / users.google_id unique designs T2's upsert relies on.
// Run: vendor/bin/phpunit tests/Integration/MongoConnectionTest.php
class MongoConnectionTest extends TestCase
{
    public function test_round_trip_on_mongo_test_db(): void
    {
        $this->assertSame('vietfeed_test', config('database.connections.mongodb.database'));

        DB::connection('mongodb')->table('spike')->delete();
        DB::connection('mongodb')->table('spike')->insert(['k' => 'round-trip', 'n' => 1]);

        $doc = DB::connection('mongodb')->table('spike')->where('k', 'round-trip')->first();

        $this->assertSame(1, $doc->n);
        DB::connection('mongodb')->table('spike')->delete();
    }

    public function test_user_unique_indexes_enforced(): void
    {
        Schema::connection('mongodb')->table('users', function ($collection) {
            $collection->unique('email');
            $collection->sparse_and_unique('google_id');
        });

        DB::connection('mongodb')->table('users')->delete();
        DB::connection('mongodb')->table('users')->insert(['email' => 'dup@example.com']);

        try {
            DB::connection('mongodb')->table('users')->insert(['email' => 'dup@example.com']);
            $this->fail('duplicate email insert must fail');
        } catch (BulkWriteException $e) {
            $this->assertSame(11000, $e->getCode());
        }

        // Sparse: several docs without google_id coexist.
        DB::connection('mongodb')->table('users')->insert(['email' => 'g1@example.com']);
        DB::connection('mongodb')->table('users')->insert(['email' => 'g2@example.com']);
        $this->assertSame(3, DB::connection('mongodb')->table('users')->count());
        DB::connection('mongodb')->table('users')->delete();
    }
}
