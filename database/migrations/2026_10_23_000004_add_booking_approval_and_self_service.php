<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAL-08: owners may approve agent bookings before they're confirmed.
 * CAL-09: customers may change or cancel online up to N hours before (null = not offered).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('approve_agent_bookings')->default(false)->after('average_job_value_cents');
            $table->unsignedSmallInteger('customer_change_hours')->nullable()->default(24)->after('approve_agent_bookings');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['approve_agent_bookings', 'customer_change_hours']);
        });
    }
};
