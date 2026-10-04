<?php

use App\Support\Authorization\RoleCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Create permissions and global roles from config/authorization.php and give
     * existing admins/agents their default role. Re-run after catalogue changes
     * with: php artisan permissions:sync
     */
    public function up(): void
    {
        app(RoleCatalog::class)->sync();
    }

    public function down(): void
    {
        // Permission tables are dropped by the previous migration's down().
    }
};
