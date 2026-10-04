<?php

use App\Services\Customers\CustomerBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Existing calls get customers and timelines on deploy.
     * Preview with: php artisan customers:backfill --dry-run
     */
    public function up(): void
    {
        app(CustomerBackfill::class)->run();
    }

    public function down(): void
    {
        // Customers and timelines are dropped with their tables by the previous migration's down().
    }
};
