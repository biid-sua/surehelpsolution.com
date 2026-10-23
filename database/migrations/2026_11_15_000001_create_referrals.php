<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referrals (D90): a business shares its link; when a business it referred pays its first invoice, the
 * referrer gets a month's credit, taken off its next invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('referral_code', 12)->nullable()->unique();
        });
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('referred_organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->string('status', 12)->default('signed_up');   // signed_up | rewarded
            $table->unsignedInteger('credit_cents')->default(0);
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();
        });
        Schema::create('billing_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 200);
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('used_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_credits');
        Schema::dropIfExists('referrals');
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('referral_code');
        });
    }
};
