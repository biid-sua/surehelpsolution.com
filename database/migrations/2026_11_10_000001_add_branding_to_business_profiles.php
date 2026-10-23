<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * White-label (D82): a business's own colour and email sender name, used on everything its customers
 * see when its plan includes white-label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->string('brand_color', 7)->nullable();
            $table->string('email_sender_name', 80)->nullable();
            $table->boolean('brand_portal')->default(true);   // also show the logo and colour in the business's own portal
        });
    }

    public function down(): void
    {
        Schema::table('business_profiles', function (Blueprint $table) {
            $table->dropColumn(['brand_color', 'email_sender_name', 'brand_portal']);
        });
    }
};
