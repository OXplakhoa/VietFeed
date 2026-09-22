<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sanctions', function (Blueprint $table) {
            $table->id();
            // Gate 3: users live on Mongo (string _id) — no SQL FK, no cascade (app-level later).
            $table->string('user_id')->index();
            $table->string('admin_id')->index();
            $table->foreignId('report_id')->nullable()->constrained('reports')->nullOnDelete();
            $table->enum('type', ['warning', 'mute', 'temporary_ban', 'permanent_ban']);
            $table->text('reason');
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('appeal_message')->nullable();
            $table->enum('appeal_status', ['none', 'pending', 'approved', 'denied'])->default('none');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanctions');
    }
};
