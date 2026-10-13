<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where an appointment happens (D56): an exact address in parts, one of the business's locations, or remote.
     * `appointments.address` stays the one-line form every reader (emails, calendars, rules, exports) already uses.
     * Customers keep any number of saved locations for future bookings.
     */
    public function up(): void
    {
        Schema::create('customer_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100)->nullable();           // "Lake house", "Office"
            $table->string('type', 20)->default('residential'); // residential | commercial | other
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('city', 100);
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country', 2)->default('US');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'customer_id']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('location_mode', 10)->nullable()->after('address'); // address | business | remote; null = older bookings
            $table->string('place_name', 100)->nullable()->after('location_mode');
            $table->string('place_type', 20)->nullable()->after('place_name');
            $table->string('address_line1')->nullable()->after('place_type');
            $table->string('address_line2')->nullable()->after('address_line1');
            $table->string('city', 100)->nullable()->after('address_line2');
            $table->string('state', 100)->nullable()->after('city');
            $table->string('postal_code', 20)->nullable()->after('state');
            $table->char('country', 2)->nullable()->after('postal_code');
            $table->foreignId('customer_location_id')->nullable()->after('country')->constrained('customer_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_location_id');
            $table->dropColumn(['location_mode', 'place_name', 'place_type', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country']);
        });
        Schema::dropIfExists('customer_locations');
    }
};
