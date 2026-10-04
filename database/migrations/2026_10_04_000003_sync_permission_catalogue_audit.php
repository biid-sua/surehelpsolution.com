<?php

use App\Support\Authorization\RoleCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The catalogue gained `audit_logs.view` (P1-5). Re-sync so existing roles get it.
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
