<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account security (D8, D24): two-step sign-in, signing out everywhere, team invitations
 * from business owners, and a record of which legal documents each person accepted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');          // encrypted
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');   // encrypted, hashed codes
            $table->dateTime('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->unsignedInteger('session_epoch')->default(0)->after('remember_token');     // bump = sign out everywhere
            $table->string('timezone', 64)->nullable()->after('phone');
            $table->dateTime('last_login_at')->nullable()->after('session_epoch');
        });

        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20);                       // manager | staff
            $table->string('token_hash', 64)->unique();       // sha256 of the emailed token
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('expires_at');
            $table->dateTime('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'email']);
        });

        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document', 30);
            $table->string('version', 30);
            $table->dateTime('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->unique(['user_id', 'document', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('organization_invitations');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'session_epoch', 'timezone', 'last_login_at']);
        });
    }
};
