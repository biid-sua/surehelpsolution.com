<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification texts (D63): the mobile number someone proved is theirs, and the one-time code
 * (hashed) used to prove it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sms_phone', 20)->nullable()->after('phone_seen_at');
            $table->timestamp('sms_phone_verified_at')->nullable()->after('sms_phone');
            $table->string('sms_code_hash')->nullable()->after('sms_phone_verified_at');
            $table->timestamp('sms_code_expires_at')->nullable()->after('sms_code_hash');
            $table->unsignedTinyInteger('sms_code_attempts')->default(0)->after('sms_code_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['sms_phone', 'sms_phone_verified_at', 'sms_code_hash', 'sms_code_expires_at', 'sms_code_attempts']);
        });
    }
};
