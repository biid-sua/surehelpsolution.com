<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO tasks the business starts use AI credits beyond the SEO allowance too (D100): the credit entry
 * points at the task, once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_entries', function (Blueprint $table) {
            $table->foreignId('seo_task_id')->nullable()->unique()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credit_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seo_task_id');
        });
    }
};
