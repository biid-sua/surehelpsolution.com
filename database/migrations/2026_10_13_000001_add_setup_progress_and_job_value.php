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
        // Each column only if missing: a run that stopped part-way on an older MySQL left some of them behind.
        $columns = [
            'setup_progress' => fn (Blueprint $t) => $t->json('setup_progress')->nullable()->after('currency'), // step => done | skipped
            'setup_completed_at' => fn (Blueprint $t) => $t->dateTime('setup_completed_at')->nullable()->after('setup_progress'),
            'average_job_value_cents' => fn (Blueprint $t) => $t->unsignedInteger('average_job_value_cents')->nullable()->after('setup_completed_at'),
            'last_report_month' => fn (Blueprint $t) => $t->string('last_report_month', 7)->nullable()->after('average_job_value_cents'), // YYYY-MM, monthly email sent
        ];
        foreach ($columns as $name => $add) {
            if (! Schema::hasColumn('organizations', $name)) {
                Schema::table('organizations', $add);
            }
        }

        // Businesses that already set up hours or services before the wizard existed don't need it.
        // Two IN subqueries rather than a UNION: older MySQL rejects a parenthesised UNION inside IN.
        DB::table('organizations')->whereNull('setup_completed_at')
            ->where(fn ($q) => $q->whereIn('id', DB::table('business_hours')->select('organization_id'))
                ->orWhereIn('id', DB::table('business_services')->select('organization_id')))
            ->update(['setup_completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['setup_progress', 'setup_completed_at', 'average_job_value_cents', 'last_report_month']);
        });
    }
};
