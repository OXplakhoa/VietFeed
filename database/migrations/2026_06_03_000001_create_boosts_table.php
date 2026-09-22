<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boosts', function (Blueprint $table) {
            $table->id();
            // Gate 3: users live on Mongo (string _id) — no SQL FK, no cascade (app-level later).
            $table->string('user_id')->index();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'article_id']);
            $table->index(['article_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boosts');
    }
};
