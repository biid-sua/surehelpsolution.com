<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency resellers (D118): a marketing agency that brings and looks after many small businesses,
 * under its own brand, with a dashboard across them and a share of what they pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('name', 120);
            $table->string('brand_color', 7)->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('agency_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('member');
            $table->timestamps();
            $table->unique(['agency_id', 'user_id']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
        });

        // Which business memberships came from an agency, so they leave with it.
        Schema::table('organization_user', function (Blueprint $table) {
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organization_user', fn (Blueprint $table) => $table->dropConstrainedForeignId('agency_id'));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropConstrainedForeignId('agency_id'));
        Schema::dropIfExists('agency_user');
        Schema::dropIfExists('agencies');
    }
};
