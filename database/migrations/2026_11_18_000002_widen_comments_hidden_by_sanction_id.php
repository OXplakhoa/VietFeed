<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Gate 9b: sanctions now live on MongoDB with UUIDv7 ids, so the SQL comment keeps the
// cross-store reference as a string. Existing integer ids become their decimal string
// and are remapped to UUIDs by `php artisan moderation:import-sql`.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('comments', 'hidden_by_sanction_id')) {
            return;
        }
        Schema::table('comments', function (Blueprint $table) {
            $table->string('hidden_by_sanction_id', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Forward-only: UUID references cannot be narrowed back to integers without losing evidence.
    }
};
