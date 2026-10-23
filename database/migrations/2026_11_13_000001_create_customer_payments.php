<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deposits and pay-by-text (D85): each business connects its own Stripe account (Stripe Connect) and
 * asks its customers to pay through a link. Money goes straight to the business; we never hold it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('stripe_account_id', 64)->unique();
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);
            $table->string('currency', 3)->default('usd');
            $table->boolean('deposit_enabled')->default(false);          // ask for a deposit when customers book online
            $table->unsignedInteger('deposit_cents')->default(0);
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10);                        // deposit | payment
            $table->string('description', 200);
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('usd');
            $table->string('status', 12)->default('open');     // open | paid | cancelled | refunded
            $table->string('token', 40)->unique();
            $table->string('checkout_session_id', 120)->nullable()->index();
            $table->string('checkout_url', 1000)->nullable();
            $table->timestamp('checkout_expires_at')->nullable();
            $table->string('payment_intent_id', 120)->nullable()->index();
            $table->unsignedInteger('fee_cents')->default(0);   // SureHelp's application fee, if any
            $table->timestamp('paid_at')->nullable();
            $table->json('sent_via')->nullable();               // ["sms", "email"]
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
        Schema::dropIfExists('payment_accounts');
    }
};
