<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configurable call outcomes (spec §14). Rows without an organization are the platform
     * defaults; a business's rows either override a default (same key) or add its own.
     */
    public function up(): void
    {
        Schema::create('call_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('label');
            $table->string('category', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
        });

        // The keys agents have always used, so existing calls keep their meaning.
        $defaults = [
            ['scheduled-appointment', 'Appointment booked', 'booked'],
            ['resolved-by-agent', 'Resolved by agent', 'information'],
            ['provided-information', 'Provided information only', 'information'],
            ['forwarded-to-client', 'Forwarded to the business', 'information'],
            ['callback-requested', 'Caller requested a call back', 'callback'],
            ['followup-scheduled', 'Follow-up scheduled', 'callback'],
            ['escalated-to-client', 'Escalated to the business', 'escalated'],
            ['call-dropped', 'Call dropped / incomplete', 'missed'],
            ['no-response', 'No response from caller', 'missed'],
            ['wrong-number', 'Wrong number / spam', 'spam'],
            ['other', 'Other (see notes)', 'other'],
        ];

        $now = now();
        DB::table('call_outcomes')->insert(array_map(fn (array $row, int $i) => [
            'organization_id' => null,
            'key' => $row[0],
            'label' => $row[1],
            'category' => $row[2],
            'is_active' => true,
            'sort_order' => ($i + 1) * 10,
            'created_at' => $now,
            'updated_at' => $now,
        ], $defaults, array_keys($defaults)));
    }

    public function down(): void
    {
        Schema::dropIfExists('call_outcomes');
    }
};
