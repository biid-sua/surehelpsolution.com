<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business rules stored as data (spec §23): a type plus its settings, read by the booking,
     * availability and escalation code and shown to agents.
     */
    public function up(): void
    {
        Schema::create('business_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);       // BusinessRuleType
            $table->json('config');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'is_active', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_rules');
    }
};
