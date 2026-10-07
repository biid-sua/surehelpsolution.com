<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Choose your password" links in welcome emails (D51): kept apart from password resets so they can
 * last a week without making reset links last longer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_welcome_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_welcome_tokens');
    }
};
