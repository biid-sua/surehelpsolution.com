<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The business's link-in-bio page (G-6, D69): on or off, and its own extra links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->boolean('link_page_enabled')->default(false);
            $table->json('link_page_links')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn(['link_page_enabled', 'link_page_links']);
        });
    }
};
