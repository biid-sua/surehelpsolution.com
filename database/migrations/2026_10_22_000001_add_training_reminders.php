<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Training reminders are sent once each (brief §1.14: "do not spam"): these record when, and are
 * cleared when a new due date or a new cycle makes the reminder relevant again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_assignments', function (Blueprint $table) {
            $table->timestamp('due_soon_notified_at')->nullable()->after('due_at');
            $table->timestamp('overdue_notified_at')->nullable()->after('due_soon_notified_at');
        });
        Schema::table('training_certificates', function (Blueprint $table) {
            $table->timestamp('expiring_notified_at')->nullable()->after('expires_at');
            $table->timestamp('expired_notified_at')->nullable()->after('expiring_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('training_assignments', fn (Blueprint $table) => $table->dropColumn(['due_soon_notified_at', 'overdue_notified_at']));
        Schema::table('training_certificates', fn (Blueprint $table) => $table->dropColumn(['expiring_notified_at', 'expired_notified_at']));
    }
};
