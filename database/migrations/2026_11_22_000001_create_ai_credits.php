<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI credits (D100). Businesses buy packs; a purchase waits for its invoice to be paid, then adds its
 * credits. Every change to a balance is one row in `credit_entries`: bought, given by staff, or used
 * by an AI change beyond the monthly allowance. The balance is their sum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('credits');
            $table->unsignedInteger('price_cents');
            $table->string('currency', 3)->default('USD');
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('granted_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('credit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->integer('credits');
            $table->string('kind', 20);
            $table->string('note', 200);
            $table->foreignId('credit_purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_edit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
            $table->unique('credit_purchase_id');
            $table->unique('site_edit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_entries');
        Schema::dropIfExists('credit_purchases');
    }
};
