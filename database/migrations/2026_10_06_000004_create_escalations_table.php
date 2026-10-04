<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Escalations (spec §25): things that need the business's attention now.
     */
    public function up(): void
    {
        Schema::create('escalations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('call_log_id')->nullable()->constrained('call_logs')->nullOnDelete();
            $table->string('type', 30);                          // EscalationType
            $table->string('priority', 10)->default('high');     // urgent | high | normal
            $table->string('status', 20)->default('open');       // open | acknowledged | resolved
            $table->string('reason');
            $table->text('details')->nullable();
            $table->string('source', 20)->default('manual');     // call | manual | ai (later)
            $table->foreignId('raised_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'priority']);
            $table->index(['status', 'priority', 'reminded_at']); // reminder sweep across businesses
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalations');
    }
};
