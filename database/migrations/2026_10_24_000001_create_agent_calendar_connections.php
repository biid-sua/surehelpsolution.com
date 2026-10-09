<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An agent's own Google or Microsoft calendar (D54): their busy times appear on My calendar, and
 * their SureHelp shifts can be copied into it. Separate from businesses' calendar connections,
 * which belong to a company and feed its booking availability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('account_email')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->json('calendars')->nullable();          // [{id, name, primary, can_write}]
            $table->json('busy_calendar_ids')->nullable();  // shown as busy on My calendar
            $table->string('write_calendar_id')->nullable(); // where shifts are copied
            $table->boolean('push_shifts')->default(true);
            $table->string('status', 20)->default('active');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider']);
        });

        // Which shift became which event, so changes update it and removed shifts delete it.
        Schema::create('agent_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('agent_calendar_connections')->cascadeOnDelete();
            $table->unsignedBigInteger('shift_id');
            $table->string('external_id');
            $table->string('calendar_id');
            $table->string('fingerprint', 40);
            $table->timestamps();
            $table->unique(['connection_id', 'shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_calendar_events');
        Schema::dropIfExists('agent_calendar_connections');
    }
};
