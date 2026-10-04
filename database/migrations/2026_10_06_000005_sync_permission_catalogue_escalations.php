<?php

use App\Support\Authorization\RoleCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The catalogue gained `escalations.view|create|resolve` (P2-4c). Re-sync so existing roles get them.
     */
    public function up(): void
    {
        app(RoleCatalog::class)->sync();
    }

    public function down(): void
    {
        //
    }
};
