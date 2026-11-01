<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connected apps (D110): a business's own software, starting with QuickBooks Online. One connection
 * per app, which records match on both sides (so nothing is sent twice), and a short history of what
 * was sent and received.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_connections', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('account_id', 64)->nullable();
            $table->string('account_name', 190)->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->json('settings')->nullable();
            $table->string('status', 20)->default('active');
            $table->dateTime('pulled_through')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'provider']);
        });

        Schema::create('app_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_connection_id')->constrained()->cascadeOnDelete();
            $table->string('local_type', 40);
            $table->unsignedBigInteger('local_id');
            $table->string('external_id', 64);
            $table->timestamps();
            $table->unique(['app_connection_id', 'local_type', 'local_id']);
            $table->index(['app_connection_id', 'local_type', 'external_id']);
        });

        Schema::create('app_sync_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_connection_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('label', 190);
            $table->string('error', 500)->nullable();
            $table->dateTime('created_at');
            $table->index(['app_connection_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_sync_entries');
        Schema::dropIfExists('app_links');
        Schema::dropIfExists('app_connections');
    }
};
