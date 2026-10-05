<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding wizard progress (spec ONB), and for the Results page (spec RPT-01/02) the average job
 * value behind estimated revenue and which month's report email was last sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('setup_progress')->nullable()->after('currency');      // step => done | skipped
            $table->dateTime('setup_completed_at')->nullable()->after('setup_progress');
            $table->unsignedInteger('average_job_value_cents')->nullable()->after('setup_completed_at');
            $table->string('last_report_month', 7)->nullable()->after('average_job_value_cents');   // YYYY-MM, monthly email sent
        });

        // Businesses that already set up hours or services before the wizard existed don't need it.
        $configured = DB::table('business_hours')->select('organization_id')
            ->union(DB::table('business_services')->select('organization_id'));
        DB::table('organizations')->whereIn('id', $configured)->update(['setup_completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['setup_progress', 'setup_completed_at', 'average_job_value_cents', 'last_report_month']);
        });
    }
};
