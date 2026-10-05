<?php

use App\Support\Authorization\RoleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call quality reviews (spec SUP-04): calls drawn at random or picked by a supervisor, scored
 * against the scorecard, with feedback the agent reads. New `qa.review` permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_reviews', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('call_log_id')->unique()->constrained('call_logs')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20);
            $table->string('status', 20)->default('pending');
            $table->json('scores')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->boolean('passed')->nullable();
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['agent_user_id', 'reviewed_at']);
        });

        app(RoleCatalog::class)->sync();
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_reviews');
    }
};
