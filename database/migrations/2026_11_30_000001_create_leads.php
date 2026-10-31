<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instant replies to new leads (D109): each lead from the website form or a lead source (Facebook,
 * Google, Angi, Thumbtack, Yelp… through the lead link), how fast it got an answer, and each
 * business's settings for the text, the email and the call that connects the owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->boolean('text_lead')->default(true);
            $table->text('text_message')->nullable();
            $table->boolean('email_lead')->default(true);
            $table->text('email_message')->nullable();
            $table->boolean('connect_call')->default(false);
            $table->boolean('call_in_hours_only')->default(true);
            $table->string('webhook_token', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 30);
            $table->string('name', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->text('message')->nullable();
            $table->json('details')->nullable();
            $table->dateTime('received_at');
            $table->dateTime('texted_at')->nullable();
            $table->dateTime('emailed_at')->nullable();
            $table->dateTime('first_response_at')->nullable();
            $table->string('call_status', 20)->nullable();
            $table->string('call_sid', 64)->nullable();
            $table->dateTime('connected_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_settings');
    }
};
