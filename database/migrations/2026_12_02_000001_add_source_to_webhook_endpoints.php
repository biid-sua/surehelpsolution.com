<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Zapier app (D113) adds and removes webhook addresses itself: mark those, so the business sees
 * which ones Zapier manages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
