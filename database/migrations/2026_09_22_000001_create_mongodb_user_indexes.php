<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// Gate 3 T2: durable unique indexes for the users document (verified live in T1).
// Runs on the mongodb connection under every `migrate` (dev + fresh clones);
// createIndex is idempotent, so re-runs are safe. Compose Mongo must be up
// whenever migrations run (same prerequisite as the test suite).
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mongodb')->table('users', function ($collection) {
            $collection->unique('email');
            $collection->sparse_and_unique('google_id');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->table('users', function ($collection) {
            $collection->dropIndexIfExists('email_1');
            $collection->dropIndexIfExists('google_id_1');
        });
    }
};
