<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRM foundation (spec §12–13). One customer per normalised phone per business,
     * enforced by the database (spec §55: never rely on application-level uniqueness alone).
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('company')->nullable();
            $table->string('phone', 40)->nullable();        // as entered / displayed
            $table->string('phone_e164', 20)->nullable();   // normalised, used for matching
            $table->string('email')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('status', 20)->default('lead');
            $table->string('source', 20)->default('manual');
            $table->string('preferred_contact', 10)->nullable(); // phone | sms | email
            // Consent records (spec §12, §57): what, when, and how it was given.
            $table->boolean('sms_consent')->default(false);
            $table->timestamp('sms_consent_at')->nullable();
            $table->boolean('email_consent')->default(false);
            $table->timestamp('email_consent_at')->nullable();
            $table->string('consent_source', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'phone_e164']);
            $table->index(['organization_id', 'email']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'last_activity_at']);
            $table->index(['organization_id', 'last_name', 'first_name']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('color', 20)->default('brand');
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('customer_tag', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['customer_id', 'tag_id']);
        });

        Schema::create('customer_timeline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::table('call_logs', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
        Schema::dropIfExists('customer_timeline_events');
        Schema::dropIfExists('customer_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('customers');
    }
};
