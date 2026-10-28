<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A business's own domain for its hosted website (D101): the address, where connecting it has got to
 * (pending → active, or failed), the certificate provider's reference, and the last check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('custom_domain', 253)->nullable()->unique();
            $table->string('domain_status', 20)->nullable();
            $table->string('domain_provider_id', 100)->nullable();
            $table->string('domain_error', 300)->nullable();
            $table->timestamp('domain_checked_at')->nullable();
            $table->timestamp('domain_active_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique(['custom_domain']);
            $table->dropColumn(['custom_domain', 'domain_status', 'domain_provider_id', 'domain_error', 'domain_checked_at', 'domain_active_at']);
        });
    }
};
