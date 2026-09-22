<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            // Gate 3: users live on Mongo (string _id) — no SQL FK, no cascade (app-level later).
            $table->string('user_id')->nullable()->index();
            $table->string('session_id', 200)->nullable()->index();
            $table->timestamp('unlocked_at')->index();
            $table->timestamps();

            // One unlock per article per user or session
            $table->unique(['article_id', 'user_id']);
            $table->unique(['article_id', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_unlocks');
    }
};
