<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// Gate 3 T3: durable unique index on articles.original_url (dedup key for the
// feed ingestion updateOrCreate path). Idempotent re-runs; compose Mongo up.
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mongodb')->table('articles', function ($collection) {
            $collection->unique('original_url');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->table('articles', function ($collection) {
            $collection->dropIndexIfExists('original_url_1');
        });
    }
};
