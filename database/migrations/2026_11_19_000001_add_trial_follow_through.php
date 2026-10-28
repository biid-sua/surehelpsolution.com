<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-serve free trials (D97): the owner confirms their plan before the trial ends, or the trial ends
 * and the account becomes read-only. Reminders sent are remembered, and so is the sample data a new
 * trial starts with, so it can be removed in one click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('confirm_by_trial_end')->default(false);
            $table->timestamp('confirmed_at')->nullable();
            $table->string('ended_reason', 30)->nullable();
            $table->text('trial_reminders')->nullable();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->text('sample_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['confirm_by_trial_end', 'confirmed_at', 'ended_reason', 'trial_reminders']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('sample_data');
        });
    }
};
