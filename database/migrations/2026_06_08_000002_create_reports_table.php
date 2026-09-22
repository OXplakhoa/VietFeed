<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            // Gate 3: users live on Mongo (string _id) — no SQL FK, no cascade (app-level later).
            $table->string('reporter_id')->index();
            $table->foreignId('comment_id')->constrained('comments')->cascadeOnDelete();
            $table->string('admin_id')->nullable()->index();
            $table->enum('reason', ['hate_speech', 'harassment', 'spam', 'misinformation', 'sexual_content', 'other']);
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'reviewed', 'actioned', 'dismissed', 'false_report'])->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['reporter_id', 'comment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
