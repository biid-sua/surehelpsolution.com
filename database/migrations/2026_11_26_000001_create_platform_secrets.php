<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keys the SureHelp team enters under Admin › Connections (D104): AI providers and the social
 * networks' app credentials. Values are encrypted with the app key; they override .env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_secrets', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_secrets');
    }
};
