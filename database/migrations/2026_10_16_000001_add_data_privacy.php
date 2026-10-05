<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data and privacy (spec §56–57, §91; task.md CMP-05, CMP-07): full data exports, how long call
 * history is kept, erasing a customer's personal data, and closing a business account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('pending');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            // Call history and closed work older than this is deleted (null = kept).
            $table->unsignedSmallInteger('retention_months')->nullable()->default(36)->after('average_job_value_cents');
            $table->timestamp('closure_requested_at')->nullable()->after('retention_months');
            $table->timestamp('closes_at')->nullable()->after('closure_requested_at');
            $table->foreignId('closure_requested_by')->nullable()->after('closes_at')->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('closure_requested_by');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('erased_at')->nullable()->after('merged_into_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('erased_at');
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closure_requested_by');
            $table->dropColumn(['retention_months', 'closure_requested_at', 'closes_at', 'closed_at']);
        });
        Schema::dropIfExists('data_exports');
    }
};
