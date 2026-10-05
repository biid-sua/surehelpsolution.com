<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing extras (task.md BIL-03, BIL-05, BIL-11, ADD-01..04): usage per billing period with
 * extra-call charges, usage alerts, and paid add-ons.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 30);                        // calls (minutes, sms, ai later)
            $table->date('period_start');
            $table->date('period_end');                          // exclusive
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('included')->nullable();
            $table->unsignedInteger('overage')->default(0);
            $table->unsignedInteger('unit_cents')->default(0);
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['subscription_id', 'metric', 'period_start']);   // billed once, however often billing runs
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // Usage alerts already sent in the current period: {"period": "Y-m-d", "levels": [80, 100]}.
            $table->json('usage_alerts')->nullable()->after('cancelled_at');
        });

        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();                 // also the feature key it unlocks
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents');
            $table->char('currency', 3)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('organization_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained()->restrictOnDelete();
            $table->string('status', 12);                         // active | ended
            $table->unsignedInteger('price_cents');               // locked when turned on, like plans
            $table->date('started_on');
            $table->boolean('cancel_at_period_end')->default(false);
            $table->date('ended_on')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_addons');
        Schema::dropIfExists('addons');
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('usage_alerts');
        });
        Schema::dropIfExists('usage_records');
    }
};
