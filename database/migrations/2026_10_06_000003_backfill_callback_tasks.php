<?php

use App\Services\Tasks\TaskBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Calls still waiting for a call back become tasks on deploy.
     * Idempotent; safe to re-run later with: php artisan tasks:backfill [--dry-run]
     */
    public function up(): void
    {
        app(TaskBackfill::class)->run();
    }

    public function down(): void
    {
        // Tasks are dropped with their table by the previous migration's down().
    }
};
