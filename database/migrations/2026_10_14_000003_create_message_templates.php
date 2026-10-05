<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emails to a business's own customers (spec §26–27): appointment confirmations, reminders, changes
 * and cancellations, each with a template the business can edit or switch off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 50);
            $table->string('subject');
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('lead_hours')->nullable();   // reminders: how long before
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dateTime('reminder_sent_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
        Schema::dropIfExists('message_templates');
    }
};
