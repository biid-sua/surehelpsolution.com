<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo calls after the booking (D96): a private link to move or cancel, reminders, who runs it, what
 * happened (attended, no-show) and what came of it (trial, customer), plus the follow-up emails sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->string('demo_token', 40)->nullable()->unique();
            $table->timestamp('demo_booked_at')->nullable();
            $table->foreignId('demo_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('demo_outcome', 20)->nullable();
            $table->foreignId('signed_up_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->timestamp('demo_reminded_day_at')->nullable();
            $table->timestamp('demo_reminded_hour_at')->nullable();
            $table->timestamp('demo_followed_up_at')->nullable();
            $table->timestamp('demo_nudged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('demo_owner_user_id');
            $table->dropConstrainedForeignId('signed_up_organization_id');
            $table->dropUnique(['demo_token']);
            $table->dropColumn(['demo_token', 'demo_booked_at', 'demo_outcome', 'demo_reminded_day_at', 'demo_reminded_hour_at', 'demo_followed_up_at', 'demo_nudged_at']);
        });
    }
};
