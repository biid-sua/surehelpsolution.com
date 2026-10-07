<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why and when SureHelp staff last changed a business's service status (go live, pause, cancel; D45).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('status_reason', 500)->nullable()->after('status');
            $table->timestamp('status_changed_at')->nullable()->after('status_reason');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['status_reason', 'status_changed_at']);
        });
    }
};
