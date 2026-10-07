<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connect a website (spec §41B, G-3, docs/decisions.md D44): a business's own sites, proof that
 * they own them, the latest health and SEO check, and what the one snippet adds to the site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('url', 255);                          // normalised home page, e.g. https://riverplumbing.com/
            $table->string('host', 191);
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_via', 10)->nullable();      // meta | dns
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedTinyInteger('health_score')->nullable();
            $table->json('health')->nullable();                  // findings of the last check
            $table->string('last_error', 255)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'host']);
            $table->index(['verified_at', 'last_checked_at']);
        });

        Schema::table('chat_widgets', function (Blueprint $table) {
            // What the snippet shows: {"chat": true, "booking": false, "call": false, "lead": false}.
            $table->json('features')->nullable()->after('allowed_origins');
            $table->boolean('bookings_need_confirmation')->default(true)->after('features');
        });
    }

    public function down(): void
    {
        Schema::table('chat_widgets', function (Blueprint $table) {
            $table->dropColumn(['features', 'bookings_need_confirmation']);
        });
        Schema::dropIfExists('websites');
    }
};
