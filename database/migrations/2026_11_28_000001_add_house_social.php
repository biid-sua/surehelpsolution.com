<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SureHelp's own social media (D104): SureHelp is an internal organization (never a customer, kept out
 * of business lists and billing), and each post the scheduler prepares records its time slot, so a
 * slot is filled once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->index();
        });
        Schema::table('social_posts', function (Blueprint $table) {
            $table->string('autopilot_slot', 20)->nullable();
            $table->unique(['organization_id', 'autopilot_slot']);
        });
    }

    public function down(): void
    {
        Schema::table('social_posts', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'autopilot_slot']);
            $table->dropColumn('autopilot_slot');
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['is_internal']);
            $table->dropColumn('is_internal');
        });
    }
};
