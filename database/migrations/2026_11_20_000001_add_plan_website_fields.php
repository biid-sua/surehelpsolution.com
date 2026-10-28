<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plans drive the website's pricing section (D98): who each plan is for, the yearly price shown per
 * month, the "Most popular" badge, and plans priced on request (Enterprise). On an install that
 * already has plans, the four the website listed until now are added if they're missing, so the
 * pricing section keeps showing them; staff then edit, hide or delete them in Admin › Billing › Plans.
 * A new, empty install starts with none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('tagline', 160)->nullable();
            $table->integer('annual_monthly_cents')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('contact_only')->default(false);
        });

        if (! DB::table('plans')->exists()) {
            return;
        }
        $now = now();
        $plans = [
            ['essential', 'Essential', 30000, 27000, 200, 'Best for solo professionals & coaches', false, false, 1,
                "100 minutes a month included\n24/7 call answering\nAppointment scheduling\nSMS and email notifications\nClient portal and app access\nBasic follow-up on up to 50 calls a month\nMessage taking and forwarding"],
            ['professional', 'Professional', 40000, 36000, 150, 'Best for home services & growing businesses', false, false, 2,
                "150 minutes a month included\nEverything in Essential\nCustom call scripts\nFull follow-up on every call\nMonthly performance reports\nPriority notifications\nEnhanced client portal\nAdvanced analytics dashboard"],
            ['growth', 'Growth', 45000, 40500, 125, 'Best for medical, legal & financial practices', true, false, 3,
                "200 minutes a month included\nEverything in Professional\nDedicated account manager\nAdvanced analytics and reporting\nPriority support and white-label options\n2x call follow-up and priority routing"],
            ['enterprise', 'Enterprise', 0, null, null, 'Best for franchises & multi-location businesses', false, true, 4,
                "Custom minutes\nEverything in Growth\nDedicated franchise teams\nCustom integrations and automation\nWhite-glove onboarding\nGuaranteed SLAs and priority escalation\nVolume pricing and tailored contracts"],
        ];
        foreach ($plans as [$slug, $name, $price, $annual, $extra, $tagline, $featured, $contact, $order, $lines]) {
            if (DB::table('plans')->where('slug', $slug)->exists()) {
                continue;
            }
            DB::table('plans')->insert([
                'slug' => $slug, 'name' => $name, 'description' => $lines, 'price_cents' => $price, 'currency' => 'USD', 'interval' => 'month',
                'trial_days' => $price > 0 ? 14 : 0, 'limits' => $extra !== null ? json_encode(['extra_call_cents' => $extra]) : null,
                'is_public' => true, 'is_active' => true, 'sort_order' => $order, 'tagline' => $tagline, 'annual_monthly_cents' => $annual,
                'is_featured' => $featured, 'contact_only' => $contact, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['tagline', 'annual_monthly_cents', 'is_featured', 'contact_only']);
        });
    }
};
