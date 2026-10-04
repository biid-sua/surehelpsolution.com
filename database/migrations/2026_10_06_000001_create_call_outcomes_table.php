<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configurable call outcomes (spec §14). Rows with organization_id NULL are the
     * platform defaults; a business row with the same key overrides its label or
     * switches it off, and extra keys are the business's own outcomes.
     */
    public function up(): void
    {
        Schema::create('call_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('label');
            $table->string('category', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
            $table->index(['category', 'key']);
        });

        // Keys match what agents and the mobile app already send, so history stays meaningful.
        $defaults = [
            ['scheduled-appointment', 'Appointment booked', 'booked'],
            ['resolved-by-agent', 'Resolved by agent', 'information'],
            ['provided-information', 'Information provided', 'information'],
            ['forwarded-to-client', 'Message passed to business', 'information'],
            ['callback-requested', 'Caller asked for a call back', 'callback'],
            ['followup-scheduled', 'Follow-up scheduled', 'callback'],
            ['escalated-to-client', 'Escalated to business', 'escalated'],
            ['call-dropped', 'Call dropped / incomplete', 'missed'],
            ['no-response', 'No response from caller', 'missed'],
            ['wrong-number', 'Wrong number / spam', 'spam'],
            ['other', 'Other', 'other'],
        ];

        $now = now();
        DB::table('call_outcomes')->insert(array_map(fn (array $row, int $i) => [
            'organization_id' => null,
            'key' => $row[0],
            'label' => $row[1],
            'category' => $row[2],
            'is_active' => true,
            'sort_order' => $i,
            'created_at' => $now,
            'updated_at' => $now,
        ], $defaults, array_keys($defaults)));
    }

    public function down(): void
    {
        Schema::dropIfExists('call_outcomes');
    }
};
