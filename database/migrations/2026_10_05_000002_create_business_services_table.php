<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the client's company sells (spec §11). Money in integer minor units (spec §75).
     * Soft-deleted so future appointments keep pointing at the service they were booked for.
     */
    public function up(): void
    {
        Schema::create('business_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('business_locations')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 100)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->string('price_type', 20)->default('quote_required');
            $table->unsignedInteger('price_cents')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_bookable')->default(true);
            $table->json('required_fields')->nullable();
            $table->text('agent_instructions')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_services');
    }
};
