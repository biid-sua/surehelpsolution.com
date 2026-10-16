<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEL-3 (D62): a business's own wording for appointment texts, and the state of a warm transfer
 * (the caller waiting in a conference while the agent talks to the owner).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('sms_body', 320)->nullable()->after('body');
        });

        Schema::table('voice_calls', function (Blueprint $table) {
            $table->timestamp('held_at')->nullable()->after('transferred_at');
            $table->foreignId('consult_for_id')->nullable()->after('held_at')->constrained('voice_calls')->nullOnDelete();
            $table->string('callee_call_sid', 64)->nullable()->after('consult_for_id');
        });
    }

    public function down(): void
    {
        Schema::table('voice_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consult_for_id');
            $table->dropColumn(['held_at', 'callee_call_sid']);
        });
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn('sms_body');
        });
    }
};
