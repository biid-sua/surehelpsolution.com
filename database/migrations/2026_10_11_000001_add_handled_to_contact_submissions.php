<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website enquiries get a "handled" mark in the admin console, so nobody is left without a reply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->dateTime('handled_at')->nullable()->after('ip_address');
            $table->foreignId('handled_by')->nullable()->after('handled_at')->constrained('users')->nullOnDelete();
            $table->index(['handled_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->dropIndex(['handled_at', 'created_at']);
            $table->dropConstrainedForeignId('handled_by');
            $table->dropColumn('handled_at');
        });
    }
};
