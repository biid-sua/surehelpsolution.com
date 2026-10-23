<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews and reputation (D83): a business's Google reviews, kept in step every hour, with the reply
 * posted from SureHelp and an AI draft when asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20)->default('google');
            $table->string('external_id', 255);                 // accounts/1/locations/2/reviews/abc
            $table->string('author_name', 120)->nullable();
            $table->unsignedTinyInteger('rating');              // 1–5
            $table->text('comment')->nullable();
            $table->timestamp('reviewed_at');
            $table->text('reply')->nullable();                  // as published
            $table->timestamp('replied_at')->nullable();
            $table->foreignId('replied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('draft')->nullable();                  // an AI suggestion, not yet posted
            $table->timestamp('drafted_at')->nullable();
            $table->string('status', 20)->default('new');       // new | replied | dismissed
            $table->string('error', 300)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'source', 'external_id']);
            $table->index(['organization_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
