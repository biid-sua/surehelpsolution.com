<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live calls (D60): each business's phone lines, every call that reaches them, and whether an
 * agent's softphone is ready to take calls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('provider_sid', 64)->nullable()->unique();
            $table->string('number', 20)->unique();
            $table->string('label', 80)->nullable();
            $table->string('transfer_number', 20)->nullable();
            $table->boolean('record_calls')->default(true);
            $table->string('status', 20)->default('active');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('voice_calls', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('phone_number_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);
            $table->string('provider_call_sid', 64)->unique();
            $table->string('direction', 10)->default('inbound');
            $table->string('from_number', 32)->nullable();
            $table->string('to_number', 32)->nullable();
            $table->string('status', 20)->default('ringing');
            $table->json('offered_to')->nullable();
            $table->foreignId('answered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('call_log_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('wait_seconds')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('transferred_to', 20)->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->string('recording_sid', 64)->nullable();
            $table->unsignedInteger('recording_seconds')->nullable();
            $table->json('transcript')->nullable();
            $table->text('summary')->nullable();
            $table->string('summary_status', 20)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'started_at']);
            $table->index(['answered_by_user_id', 'status']);
            $table->index('status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_status', 12)->default('offline')->after('theme_chosen_at');
            $table->timestamp('phone_status_at')->nullable()->after('phone_status');
            $table->timestamp('phone_seen_at')->nullable()->after('phone_status_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_status', 'phone_status_at', 'phone_seen_at']);
        });
        Schema::dropIfExists('voice_calls');
        Schema::dropIfExists('phone_numbers');
    }
};
