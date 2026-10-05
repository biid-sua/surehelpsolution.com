<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An index of calendar push channels by id, so a change notification finds its connection with one
 * indexed lookup instead of loading every connected calendar (docs/decisions.md D31). The list on
 * the connection (`push_channels`) stays the source; this table mirrors it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_push_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_connection_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('channel_id', 191);
            $table->string('calendar_id')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->unique(['provider', 'channel_id']);
        });

        DB::table('calendar_connections')->whereNotNull('push_channels')->orderBy('id')->each(function (object $connection) {
            foreach (json_decode((string) $connection->push_channels, true) ?: [] as $channel) {
                if (! empty($channel['id'])) {
                    DB::table('calendar_push_channels')->insertOrIgnore([
                        'calendar_connection_id' => $connection->id,
                        'provider' => $connection->provider,
                        'channel_id' => mb_substr((string) $channel['id'], 0, 191),
                        'calendar_id' => $channel['calendar_id'] ?? null,
                        'expires_at' => isset($channel['expires_at']) ? date('Y-m-d H:i:s', strtotime((string) $channel['expires_at'])) : null,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_push_channels');
    }
};
