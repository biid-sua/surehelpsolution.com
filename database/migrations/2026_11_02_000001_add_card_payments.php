<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card payments through Stripe (D64): the business's Stripe customer and saved card for automatic
 * payments, and each invoice's automatic charge attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('stripe_customer_id', 64)->nullable();
            $table->string('autopay_payment_method', 64)->nullable();
            $table->string('autopay_card_label', 60)->nullable();
            $table->timestamp('autopay_enabled_at')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('autopay_attempted_at')->nullable();
            $table->string('autopay_error', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['autopay_attempted_at', 'autopay_error']);
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['stripe_customer_id', 'autopay_payment_method', 'autopay_card_label', 'autopay_enabled_at']);
        });
    }
};
