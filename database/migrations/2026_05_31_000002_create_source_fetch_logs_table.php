<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_fetch_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('items_found')->nullable();
            $table->unsignedInteger('items_created')->nullable();
            $table->unsignedInteger('items_updated')->nullable();
            $table->unsignedInteger('valid_items')->nullable();
            $table->unsignedInteger('invalid_items')->nullable();
            $table->unsignedInteger('missing_image_count')->nullable();
            $table->unsignedInteger('date_parse_error_count')->nullable();
            $table->unsignedInteger('duplicate_count')->nullable();
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'created_at']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_fetch_logs');
    }
};
