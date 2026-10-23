<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automations 2.0, step 2 (D81): an automation can be a flow of steps (waits, actions, if / otherwise),
 * and each run remembers where it is and what it did. Automations without steps run as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->json('steps')->nullable();
        });
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->json('cursor')->nullable();
            $table->json('log')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('automation_runs', function (Blueprint $table) {
            // MySQL won't drop an index a foreign key still uses, so the key goes first.
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['customer_id', 'created_at']);
            $table->dropColumn('customer_id');
            $table->dropColumn(['cursor', 'log']);
        });
        Schema::table('automations', function (Blueprint $table) {
            $table->dropColumn('steps');
        });
    }
};
