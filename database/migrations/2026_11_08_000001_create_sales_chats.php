<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SureHelp's own sales chat on the website (D78): the chats, their messages, and what a website
 * enquiry learned from one (source, details, score and a booked demo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_chats', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('contact_submission_id')->nullable()->constrained()->nullOnDelete();
            $table->json('lead')->nullable();
            $table->string('page', 255)->nullable();
            $table->string('referrer', 255)->nullable();
            $table->json('utm')->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->boolean('needs_human')->default(false);
            // The visitor asked for a demo, and the time they picked before giving their details.
            $table->boolean('wants_demo')->default(false);
            $table->string('pending_slot', 40)->nullable();
            $table->timestamp('visitor_seen_at')->nullable();
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('ai_replies')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->index(['ip_address', 'created_at']);
            $table->index('last_message_at');
        });

        Schema::create('sales_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('sales_chat_id')->constrained()->cascadeOnDelete();
            // visitor | assistant | team
            $table->string('author', 12);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            // Answered by the AI (counts towards the budget) rather than a button or a person.
            $table->boolean('by_ai')->default(false);
            $table->timestamps();
            $table->index(['sales_chat_id', 'id']);
        });

        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->string('source', 12)->default('form');
            $table->json('details')->nullable();
            $table->string('score', 8)->nullable();
            $table->timestamp('demo_at')->nullable();
            $table->string('demo_status', 12)->nullable();
            $table->index('demo_at');
        });
    }

    public function down(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->dropIndex(['demo_at']);
            $table->dropColumn(['source', 'details', 'score', 'demo_at', 'demo_status']);
        });
        Schema::dropIfExists('sales_chat_messages');
        Schema::dropIfExists('sales_chats');
    }
};
