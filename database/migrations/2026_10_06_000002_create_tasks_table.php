<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tasks and follow-ups (spec §24): call-backs created from calls, and the team's own to-dos.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('call_log_id')->nullable()->constrained('call_logs')->nullOnDelete();
            $table->string('type', 20)->default('todo');          // callback | follow_up | todo
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('normal');   // urgent | high | normal | low
            $table->string('status', 20)->default('open');       // open | in_progress | completed | cancelled
            $table->string('source', 20)->default('manual');     // manual | call | backfill
            $table->timestamp('due_at')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'due_at']);
            $table->index(['organization_id', 'assigned_to_user_id', 'status']);
            $table->index(['status', 'due_at', 'overdue_notified_at']); // overdue sweep across businesses
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
