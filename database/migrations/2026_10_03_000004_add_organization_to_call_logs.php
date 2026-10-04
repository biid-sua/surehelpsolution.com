<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only: the legacy client_id column is kept for API compatibility.
     */
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
            $table->string('ownership_source', 20)->nullable()->after('organization_id');

            $table->index(['organization_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('caller_phone');
            $table->index('ownership_source');
        });
    }

    public function down(): void
    {
        // MySQL reuses the composite indexes to back the foreign keys, so the
        // foreign keys must be dropped before their indexes, then user_id's restored.
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropIndex(['ownership_source']);
            $table->dropIndex(['caller_phone']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['organization_id', 'created_at']);
            $table->dropColumn(['organization_id', 'ownership_source']);
        });

        Schema::table('call_logs', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
