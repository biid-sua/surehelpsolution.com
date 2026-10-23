<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satisfaction surveys (D84): "How did we do? 1–5" after a job or a call, answered with one tap or a
 * text reply. Happy customers are offered the Google review link; unhappy ones can tell the business why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 20)->nullable();     // appointment | call | …
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('agent_user_id')->nullable()->constrained('users')->nullOnDelete();   // who took the call
            $table->string('token', 40)->unique();
            $table->string('channel', 10);                       // sms | email
            $table->string('review_url', 500)->nullable();
            $table->timestamp('sent_at');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('score')->nullable();   // 1–5
            $table->string('answered_by', 10)->nullable();      // link | text
            $table->timestamp('answered_at')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('review_clicked_at')->nullable();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'answered_at']);
            $table->index(['customer_id', 'sent_at']);
            $table->index(['agent_user_id', 'answered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys');
    }
};
