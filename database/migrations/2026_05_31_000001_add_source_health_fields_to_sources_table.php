<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->string('last_fetch_outcome', 20)->nullable()->after('last_fetched_at');
            $table->string('last_error_type')->nullable()->after('last_fetch_outcome');
            $table->text('last_error_message')->nullable()->after('last_error_type');
            $table->timestamp('last_successful_fetch_at')->nullable()->after('last_error_message');
            $table->timestamp('last_failed_fetch_at')->nullable()->after('last_successful_fetch_at');
            $table->unsignedInteger('consecutive_failures')->default(0)->after('last_failed_fetch_at');
            $table->unsignedInteger('last_items_found')->nullable()->after('consecutive_failures');
            $table->unsignedInteger('last_valid_items')->nullable()->after('last_items_found');
            $table->unsignedInteger('last_duplicate_count')->nullable()->after('last_valid_items');
            $table->unsignedInteger('last_duration_ms')->nullable()->after('last_duplicate_count');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn([
                'last_fetch_outcome',
                'last_error_type',
                'last_error_message',
                'last_successful_fetch_at',
                'last_failed_fetch_at',
                'consecutive_failures',
                'last_items_found',
                'last_valid_items',
                'last_duplicate_count',
                'last_duration_ms',
            ]);
        });
    }
};
