<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estimates and invoices a business sends its own customers (D115). One table for both: an invoice
 * made from an estimate points back to it. Customers open them by a secret link, accept an estimate
 * or pay an invoice through the business's Stripe account (a payment request, D85).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('estimate_id')->nullable()->constrained('job_documents')->nullOnDelete();
            $table->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 10);
            $table->string('number', 20);
            $table->string('status', 12);
            $table->string('title', 150);
            $table->json('items');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedSmallInteger('tax_rate_bp')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents');
            $table->string('token', 40)->unique();
            $table->date('valid_until')->nullable();
            $table->date('due_on')->nullable();
            $table->json('sent_via')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('viewed_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->string('accepted_name', 120)->nullable();
            $table->dateTime('declined_at')->nullable();
            $table->string('declined_reason', 500)->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->string('paid_how', 20)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'type', 'number']);
            $table->index(['organization_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_documents');
    }
};
