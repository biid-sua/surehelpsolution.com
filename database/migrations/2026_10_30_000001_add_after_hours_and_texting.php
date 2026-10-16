<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone lines, part 2 (D61): where after-hours calls go, texting back missed callers, and the
 * numbers that texted STOP (never texted again until they text START).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->string('after_hours', 12)->default('agents')->after('record_calls');
            $table->boolean('text_back')->default(false)->after('after_hours');
            $table->string('text_back_message', 320)->nullable()->after('text_back');
        });

        Schema::table('voice_calls', function (Blueprint $table) {
            $table->boolean('after_hours')->default(false)->after('direction');
            $table->foreignId('placed_by_user_id')->nullable()->after('answered_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('texted_back_at')->nullable()->after('task_id');
        });

        Schema::create('sms_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->timestamp('opted_out_at');
            $table->timestamps();
            $table->unique(['organization_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_opt_outs');
        Schema::table('voice_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('placed_by_user_id');
            $table->dropColumn(['after_hours', 'texted_back_at']);
        });
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropColumn(['after_hours', 'text_back', 'text_back_message']);
        });
    }
};
