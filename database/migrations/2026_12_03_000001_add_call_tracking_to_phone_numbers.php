<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call tracking (D114): extra numbers a business puts on one marketing channel each (Google Ads, a
 * truck sign, Yelp…), so calls and booked jobs can be counted per channel, against what it costs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->string('tracking_source', 60)->nullable()->after('label');
            $table->unsignedInteger('monthly_spend_cents')->nullable()->after('tracking_source');
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropColumn(['tracking_source', 'monthly_spend_cents']);
        });
    }
};
