<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Calendar sync (spec §17–18): one connection per business per provider, and the busy times
     * mirrored from it so availability and double-booking checks never call out to Google/Microsoft.
     * Tokens are encrypted at rest (cast); only times are mirrored, never event titles (privacy).
     */
    public function up(): void
    {
        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 20);                  // google | microsoft
            $table->string('account_email')->nullable();
            $table->text('access_token');                    // encrypted
            $table->text('refresh_token')->nullable();       // encrypted
            $table->dateTime('token_expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->json('calendars')->nullable();           // cached list: id, name, primary, can_write
            $table->string('write_calendar_id')->nullable(); // where our appointments are written
            $table->json('busy_calendar_ids')->nullable();   // which calendars block time
            $table->string('status', 20)->default('active'); // active | needs_reauth | error
            $table->dateTime('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->text('push_secret')->nullable();         // encrypted; verifies webhook calls
            $table->json('push_channels')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'provider']);
            $table->index(['status', 'provider']);
        });

        Schema::create('calendar_busy_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_connection_id')->constrained()->cascadeOnDelete();
            $table->string('calendar_id');
            $table->string('external_event_id');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('all_day')->default(false);

            $table->index(['organization_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_busy_blocks');
        Schema::dropIfExists('calendar_connections');
    }
};
