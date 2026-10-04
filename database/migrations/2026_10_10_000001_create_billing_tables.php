<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing (spec §28–29): our own plans, subscriptions, invoices and payment ledger. The payment
     * provider (Payoneer today) only moves money; amounts are integer cents (spec §75). No card or
     * bank details are ever stored: payers enter them on the provider's own pages.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents');
            $table->char('currency', 3)->default('USD');
            $table->string('interval', 10)->default('month');   // month | year
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->json('features')->nullable();               // feature keys (spec §31)
            $table->json('limits')->nullable();                 // e.g. {"call_minutes": 500}
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('next_plan_id')->nullable()->constrained('plans')->nullOnDelete(); // takes effect at renewal
            $table->string('status', 20);                       // trialing | active | past_due | cancelled
            $table->unsignedInteger('price_cents');             // locked when subscribed / renewed
            $table->char('currency', 3)->default('USD');
            $table->string('interval', 10)->default('month');
            $table->date('started_on');
            $table->date('trial_ends_on')->nullable();
            $table->date('current_period_start');
            $table->date('current_period_end');                 // exclusive: the next period starts here
            $table->boolean('cancel_at_period_end')->default(false);
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['status', 'current_period_end']);
        });

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('number', 20)->unique();             // INV-2026-0001
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);                       // open | paid | void
            $table->char('currency', 3)->default('USD');
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('amount_paid_cents')->default(0);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->dateTime('issued_at');
            $table->dateTime('due_at');
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->text('payment_url')->nullable();            // Payoneer payment link for this invoice
            $table->json('billing_details')->nullable();        // who it was billed to, frozen at issue
            $table->text('notes')->nullable();
            $table->dateTime('last_reminded_at')->nullable();
            $table->unsignedTinyInteger('reminders_sent')->default(0);
            $table->dateTime('client_reported_paid_at')->nullable();
            $table->string('client_payment_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'period_start']); // one invoice per period, even if billing runs twice
            $table->index(['organization_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->integer('unit_cents');
            $table->integer('amount_cents');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('USD');
            $table->string('method', 30);                       // payoneer_card | payoneer_bank | payoneer_balance | bank_transfer | other
            $table->string('provider', 20)->default('payoneer');
            $table->string('reference')->nullable();            // provider transaction id
            $table->dateTime('received_at');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'received_at']);
            $table->unique(['provider', 'reference']);         // the same provider payment is never booked twice
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
