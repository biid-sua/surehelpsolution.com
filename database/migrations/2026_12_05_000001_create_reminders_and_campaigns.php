<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service reminders and win-back campaigns (D116): a reminder some months after a job ("time for
 * your yearly tune-up"), one-off campaigns to past customers, and customers' choice not to get them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_services', function (Blueprint $table) {
            $table->unsignedTinyInteger('reminder_months')->nullable()->after('price_cents');
            $table->string('reminder_text', 320)->nullable()->after('reminder_months');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dateTime('marketing_opt_out_at')->nullable()->after('email_consent_at');
        });

        Schema::create('service_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('business_services')->nullOnDelete();
            $table->json('channels');
            $table->dateTime('sent_at');
            $table->timestamps();
            $table->index(['organization_id', 'sent_at']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('status', 12)->default('draft');
            $table->json('audience');
            $table->json('channels');
            $table->string('text_body', 320)->nullable();
            $table->string('email_subject', 150)->nullable();
            $table->text('email_body')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->json('channels')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('service_reminders');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('marketing_opt_out_at'));
        Schema::table('business_services', fn (Blueprint $table) => $table->dropColumn(['reminder_months', 'reminder_text']));
    }
};
