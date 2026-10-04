<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Appointments (spec §16). Times are UTC instants; `timezone` records the business's zone at booking.
     * `blocked_until` = end + service buffer: the overlap check (spec §88) reads only indexed columns.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('business_services')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('business_locations')->nullOnDelete();
            $table->foreignId('call_log_id')->nullable()->constrained('call_logs')->nullOnDelete();
            $table->string('title');
            // dateTime, not timestamp: on MySQL/MariaDB without explicit_defaults_for_timestamp a NOT NULL
            // timestamp gets ON UPDATE CURRENT_TIMESTAMP (or an invalid zero default). Values are UTC (app.timezone).
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('blocked_until');
            $table->string('timezone', 64);
            $table->string('status', 20)->default('confirmed');
            $table->string('source', 20)->default('portal');      // portal | agent | api | chatbot | calendar
            $table->text('notes')->nullable();
            $table->string('address')->nullable();                // where the visit happens, when not at a location
            $table->foreignId('booked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->json('external_refs')->nullable();            // calendar event ids per provider (Phase 3)
            $table->timestamps();

            $table->index(['organization_id', 'status', 'starts_at']);
            $table->index(['organization_id', 'starts_at', 'blocked_until']);
            $table->index(['customer_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
