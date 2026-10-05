<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily summary email (spec NTF-04) and quiet hours (NTF-07), chosen per person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('daily_summary_at', 5)->nullable()->after('timezone');   // "07:30"; "off"; null = default for the role
            $table->string('quiet_hours_start', 5)->nullable()->after('daily_summary_at');
            $table->string('quiet_hours_end', 5)->nullable()->after('quiet_hours_start');
            $table->date('last_summary_on')->nullable()->after('quiet_hours_end');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['daily_summary_at', 'quiet_hours_start', 'quiet_hours_end', 'last_summary_on']);
        });
    }
};
