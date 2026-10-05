<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social publishing (spec §41, D33): connected accounts, posts with one version per account,
     * and the media library. All times are UTC (dateTime, never NOT NULL timestamp: docs/testing.md).
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('network', 20);                  // facebook | instagram | linkedin | google_business
            $table->string('external_id', 191);              // page id, IG user id, organization/person URN, location name
            $table->string('name');
            $table->string('handle')->nullable();            // @username or vanity name
            $table->string('avatar_url', 1000)->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->json('meta')->nullable();                // network extras (parent page, account resource name, ...)
            $table->boolean('is_enabled')->default(false);   // chosen by the business after connecting
            $table->string('status', 20)->default('active'); // active | needs_reauth
            $table->string('last_error', 500)->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'network', 'external_id']);
        });

        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text', 500)->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('link_url', 2000)->nullable();
            $table->string('status', 30)->default('draft');
            $table->dateTime('scheduled_at')->nullable();    // UTC; null = publish as soon as approved / now
            $table->dateTime('published_at')->nullable();
            $table->string('source', 20)->default('manual'); // manual | team | ai
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_impersonator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('review_note', 1000)->nullable(); // "changes requested" note
            $table->timestamps();

            $table->index(['organization_id', 'status', 'scheduled_at']);
        });

        Schema::create('social_post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->text('body')->nullable();                // null = use the post's text
            $table->json('options')->nullable();             // network extras: Google button, first comment, ...
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->string('external_id', 191)->nullable();
            $table->string('external_url', 1000)->nullable();
            $table->dateTime('published_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->timestamps();

            $table->unique(['social_post_id', 'social_account_id']);
            $table->index(['status', 'next_attempt_at']);   // the publishing sweep
        });

        Schema::create('social_post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);

            $table->unique(['social_post_id', 'media_asset_id']);
        });

        Schema::table('business_profiles', function (Blueprint $table) {
            // off: anyone with social.manage publishes directly; owner: posts by anyone but an owner need an owner's approval.
            $table->string('social_approval', 10)->default('owner');
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn('social_approval');
        });
        Schema::dropIfExists('social_post_media');
        Schema::dropIfExists('social_post_targets');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('social_accounts');
    }
};
