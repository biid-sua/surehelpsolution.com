<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Videos and AI-made pictures in the media library (D104): where each file came from, the prompt for
 * an AI picture, a video's length, and when the scheduler last used it (it rotates through the library).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('source', 20)->default('upload');
            $table->text('ai_prompt')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn(['source', 'ai_prompt', 'duration_seconds', 'last_used_at']);
        });
    }
};
