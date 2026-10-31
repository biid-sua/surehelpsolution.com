<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HIPAA mode (D117): for healthcare businesses that signed the Business Associate Agreement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('hipaa_mode')->default(false);
            $table->dateTime('baa_accepted_at')->nullable();
            $table->foreignId('baa_accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('baa_accepted_by_user_id');
            $table->dropColumn(['hipaa_mode', 'baa_accepted_at']);
        });
    }
};
