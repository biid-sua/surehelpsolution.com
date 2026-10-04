<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business profile, locations, opening hours and exceptions (spec §9–10).
     * Name, timezone and currency stay on `organizations`; everything else lives here.
     */
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name')->nullable();
            $table->string('legal_name')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('description')->nullable();
            $table->string('business_type', 100)->nullable();
            $table->string('industry', 100)->nullable();
            $table->string('website')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->text('service_area')->nullable();
            // Emergency availability and temporary closure (spec §10).
            $table->boolean('emergency_available')->default(false);
            $table->text('emergency_instructions')->nullable();
            $table->date('closed_until')->nullable();
            $table->string('closure_message')->nullable();
            $table->timestamps();
        });

        Schema::create('business_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country', 2)->default('US');
            $table->string('phone', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'is_primary']);
        });

        // Several rows per day = split shift; no rows for a day = closed that day.
        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('business_locations')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0 = Sunday … 6 = Saturday (Carbon)
            $table->time('opens_at');
            $table->time('closes_at'); // earlier than opens_at = closes after midnight
            $table->timestamps();

            $table->index(['organization_id', 'day_of_week']);
        });

        // Holidays (closed) and special hours (open with different times) for one date.
        Schema::create('business_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name');
            $table->boolean('is_closed')->default(true);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_holidays');
        Schema::dropIfExists('business_hours');
        Schema::dropIfExists('business_locations');
        Schema::dropIfExists('business_profiles');
    }
};
