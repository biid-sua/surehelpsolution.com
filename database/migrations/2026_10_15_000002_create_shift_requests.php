<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agents ask to hand a shift to a colleague or for time off; whoever plans the duty schedule
 * approves or declines (task.md AGT-11, SUP-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 10);
            $table->foreignId('shift_id')->nullable()->constrained('agent_duty_schedules')->nullOnDelete();
            $table->foreignId('swap_with_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('leave_from')->nullable();
            $table->date('leave_until')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 12)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_requests');
    }
};
