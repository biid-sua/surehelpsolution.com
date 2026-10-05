<?php

use App\Support\Authorization\RoleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "View as client" for support (spec ADM-05): new `users.impersonate` permission, and every audit
 * entry made while viewing as someone records the SureHelp person really behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('impersonator_id')->nullable()->after('actor_type')->constrained('users')->nullOnDelete();
        });
        app(RoleCatalog::class)->sync();
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonator_id');
        });
    }
};
