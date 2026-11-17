<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (Schema::getForeignKeys('source_fetch_logs') as $key) {
            if ($key['columns'] === ['source_id']) {
                Schema::table('source_fetch_logs', fn (Blueprint $table) => $table->dropForeign($key['name'] ?? $key['columns']));
            }
        }
        Schema::table('source_fetch_logs', fn (Blueprint $table) => $table->string('source_id', 36)->change());
    }

    public function down(): void
    {
        // Forward-only: UUIDs cannot be narrowed to SQL integer foreign keys.
    }
};
