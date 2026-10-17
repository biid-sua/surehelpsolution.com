<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI content studio for social posts (G-2, D66): the business's brand voice, each AI request with the
 * tokens it used (for the monthly allowance), and AI labels on posts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->text('brand_voice')->nullable();
        });

        Schema::create('social_ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);              // write | rewrite | plan
            $table->string('status', 20);            // queued | running | done | failed
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('error', 255)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::table('social_posts', function (Blueprint $table) {
            $table->boolean('ai_assisted')->default(false);
            $table->string('ai_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropColumn(['ai_assisted', 'ai_note']);
        });
        Schema::dropIfExists('social_ai_runs');
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn('brand_voice');
        });
    }
};
