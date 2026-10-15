<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO service (D57): website check history for before/after statistics, AI SEO tasks with their
 * token use, and Google Search Console data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->timestamp('checked_at');
            $table->unsignedTinyInteger('health_score')->nullable();
            $table->unsignedSmallInteger('problems')->default(0);
            $table->unsignedSmallInteger('warnings')->default(0);
            $table->unsignedSmallInteger('pages_checked')->default(0);
            $table->string('error', 500)->nullable();

            $table->index(['organization_id', 'checked_at']);
        });

        Schema::create('seo_tasks', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->string('status', 12)->default('queued');          // queued | running | done | failed
            $table->string('source', 12)->default('client');          // client | staff | autopilot
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('error', 500)->nullable();
            $table->string('review', 12)->nullable();                 // null | approved | dismissed
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('search_console_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('account_email')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('sites')->nullable();
            $table->string('site_url')->nullable();
            $table->json('top_queries')->nullable();
            $table->string('status', 20)->default('active');          // active | needs_reauth | error
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('seo_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('position', 6, 2)->nullable();

            $table->unique(['organization_id', 'date']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedInteger('seo_token_allowance')->nullable()->after('customer_change_hours');
            $table->json('seo_settings')->nullable()->after('seo_token_allowance');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['seo_token_allowance', 'seo_settings']);
        });
        Schema::dropIfExists('seo_daily_metrics');
        Schema::dropIfExists('search_console_connections');
        Schema::dropIfExists('seo_tasks');
        Schema::dropIfExists('website_checks');
    }
};
