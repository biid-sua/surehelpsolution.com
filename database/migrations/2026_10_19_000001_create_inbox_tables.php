<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unified inbox (spec §26, D37) and the AI messaging assistant (spec §26A, D38–D39).
     * Times are dateTime (UTC), never NOT NULL timestamp (docs/testing.md).
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);                       // web_chat | facebook | instagram (sms, email later)
            $table->string('channel_key', 191);                  // which inbox: "web", or the Page / Instagram account id
            $table->string('external_thread_id', 191);           // Meta sender id, or the hash of a website visitor's token
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_name')->nullable();
            $table->string('contact_handle')->nullable();       // @username, email or phone as given
            $table->string('status', 20)->default('open');      // open | closed
            $table->boolean('needs_human')->default(false);     // handed over, or waiting for the team
            $table->boolean('ai_paused')->default(false);       // a person took over this conversation
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('unread_count')->default(0);
            $table->dateTime('last_message_at')->nullable();
            $table->dateTime('last_inbound_at')->nullable();     // starts Meta's 24-hour reply window
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'channel', 'channel_key', 'external_thread_id'], 'conversations_thread_unique');
            $table->index(['organization_id', 'status', 'last_message_at']);
            $table->index(['organization_id', 'needs_human']);
        });

        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 10);                          // suggest | auto
            $table->string('model', 60)->nullable();
            $table->string('status', 20);                        // replied | drafted | handed_over | skipped | failed
            $table->string('reason', 500)->nullable();           // why it was skipped / failed / handed over
            $table->unsignedSmallInteger('steps')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->json('tool_calls')->nullable();              // [{name, input, ok, summary}]
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3);                      // in | out
            $table->string('author_type', 10);                   // customer | user | ai | system
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body')->nullable();
            $table->json('attachments')->nullable();             // [{type, url, name}]
            $table->boolean('is_note')->default(false);          // internal note: never sent to the customer
            $table->string('status', 12)->default('received');   // received | draft | pending | sent | failed | discarded
            $table->string('external_id', 191)->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignId('ai_run_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'direction', 'external_id']); // webhook retries never duplicate
            $table->index(['conversation_id', 'id']);
        });

        Schema::create('ai_assistants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);       // master switch
            $table->string('name', 40)->default('Assistant');
            $table->string('tone', 20)->default('friendly');     // friendly | professional | concise
            $table->json('modes')->nullable();                   // {web_chat: auto, facebook: suggest, instagram: off}
            $table->boolean('can_book')->default(true);
            $table->boolean('bookings_need_confirmation')->default(false); // AI bookings start as "pending"
            $table->string('handover_message', 500)->nullable();
            $table->text('instructions')->nullable();            // the business's own words
            $table->timestamps();
        });

        Schema::create('ai_guidelines', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('text', 1000);
            $table->string('status', 10)->default('draft');      // draft | active | archived
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('ai_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('rating', 10);                        // helpful | unhelpful
            $table->text('correction')->nullable();
            $table->foreignId('ai_guideline_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['message_id', 'user_id']);
        });

        Schema::create('chat_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('public_key', 40)->unique();
            $table->boolean('is_enabled')->default(true);
            $table->string('title', 60)->default('Chat with us');
            $table->string('greeting', 300)->nullable();
            $table->string('color', 7)->default('#7C3AED');
            $table->json('allowed_origins')->nullable();         // empty = any site
            $table->timestamps();
        });

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->boolean('messaging_enabled')->default(false); // Messenger / Instagram DMs come into the inbox
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('messaging_enabled');
        });
        Schema::dropIfExists('chat_widgets');
        Schema::dropIfExists('ai_feedback');
        Schema::dropIfExists('ai_guidelines');
        Schema::dropIfExists('ai_assistants');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('conversations');
    }
};
