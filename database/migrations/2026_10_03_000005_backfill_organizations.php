<?php

use App\Services\Tenancy\TenancyBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Moves existing clients, agents and calls into organizations on deploy,
     * so the switch to organization-scoped queries can't leave clients empty-handed.
     * Preview first with: php artisan tenancy:backfill --dry-run
     */
    public function up(): void
    {
        app(TenancyBackfill::class)->run();
    }

    public function down(): void
    {
        // Data is removed together with the tables by the previous migrations' down().
    }
};
