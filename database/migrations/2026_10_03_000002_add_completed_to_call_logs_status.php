<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The agent form offers "Completed" but the column rejected it. Additive change.
     */
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->enum('status', ['new', 'service-requested', 'information-provided', 'cancelled', 'spam', 'completed'])->change();
        });
    }

    public function down(): void
    {
        if (DB::table('call_logs')->where('status', 'completed')->exists()) {
            throw new RuntimeException('Cannot roll back: call_logs contains rows with status "completed". Reassign them first.');
        }

        Schema::table('call_logs', function (Blueprint $table) {
            $table->enum('status', ['new', 'service-requested', 'information-provided', 'cancelled', 'spam'])->change();
        });
    }
};
