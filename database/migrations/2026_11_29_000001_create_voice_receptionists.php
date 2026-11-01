<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI receptionist (D108): when it answers a business's calls and how it sounds, and on each
 * call it answered, its conversation (the transcript, in the Inbox) and how far it got.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_receptionists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mode', 20)->default('off');
            $table->text('greeting')->nullable();
            $table->string('voice', 40)->default('Polly.Joanna-Neural');
            $table->boolean('can_book')->default(true);
            $table->boolean('transfer_to_owner')->default(true);
            $table->unsignedSmallInteger('max_minutes')->default(8);
            $table->timestamps();
        });

        Schema::table('voice_calls', function (Blueprint $table) {
            $table->foreignId('ai_conversation_id')->nullable()->after('task_id')->constrained('conversations')->nullOnDelete();
            $table->unsignedSmallInteger('ai_turns')->default(0)->after('ai_conversation_id');
            $table->unsignedTinyInteger('ai_silences')->default(0)->after('ai_turns');
        });
    }

    public function down(): void
    {
        Schema::table('voice_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_conversation_id');
            $table->dropColumn(['ai_turns', 'ai_silences']);
        });
        Schema::dropIfExists('voice_receptionists');
    }
};
