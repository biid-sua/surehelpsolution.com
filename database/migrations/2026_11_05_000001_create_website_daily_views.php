<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pages viewed on a business's website where the SureHelp snippet is installed (G-5, D68): one count per
 * business per day. No visitor, address or cookie is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_daily_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('views')->default(0);
            $table->unique(['organization_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_daily_views');
    }
};
