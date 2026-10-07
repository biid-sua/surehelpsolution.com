<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automation foundation (spec §42–43, D50): a trigger, conditions, a delay and one action per
 * automation, and a run per subject with its history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('trigger', 40);
            $table->json('conditions')->nullable();
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->string('action', 30);
            $table->json('action_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'trigger', 'is_active']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('status', 12)->default('pending');   // pending | done | skipped | failed | cancelled
            $table->timestamp('run_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('result', 500)->nullable();
            $table->timestamp('ran_at')->nullable();
            $table->timestamps();

            // One run per automation and subject: a re-fired trigger never doubles an email.
            $table->unique(['automation_id', 'subject_type', 'subject_id']);
            $table->index(['status', 'run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automations');
    }
};
