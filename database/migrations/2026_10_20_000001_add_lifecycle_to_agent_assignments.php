<?php

use App\Support\Authorization\RoleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agent company assignments get a lifecycle and a history (D40): status, start and end, who
     * assigned and ended them, why. Rows are never deleted, so one agent may have several rows for
     * the same company over time: the unique pair constraint goes (after a covering index is added,
     * because MySQL's foreign key on organization_id relies on an index).
     */
    public function up(): void
    {
        Schema::table('agent_assignments', function (Blueprint $table) {
            $table->string('status', 12)->default('active')->after('agent_user_id');   // scheduled | active | suspended | ended | revoked
            $table->string('assignment_type', 12)->default('standard')->after('status'); // standard | backup | temporary
            $table->dateTime('starts_at')->nullable()->after('assignment_type');
            $table->dateTime('ends_at')->nullable()->after('starts_at');
            $table->foreignId('assigned_by_user_id')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('end_reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('status_changed_at')->nullable();

            $table->index(['organization_id', 'status']);
            $table->index(['agent_user_id', 'status']);
        });

        DB::table('agent_assignments')->whereNull('starts_at')->update(['starts_at' => DB::raw('created_at')]);

        Schema::table('agent_assignments', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'agent_user_id']);
        });

        app(RoleCatalog::class)->sync(); // new permissions: agent_assignments.*, agent_university.*, training.*
    }

    public function down(): void
    {
        // Keep the latest row per agent and company so the old unique pair can return.
        $keep = DB::table('agent_assignments')->selectRaw('MAX(id) as id')->groupBy('organization_id', 'agent_user_id')->pluck('id');
        DB::table('agent_assignments')->whereNotIn('id', $keep)->delete();

        Schema::table('agent_assignments', function (Blueprint $table) {
            $table->unique(['organization_id', 'agent_user_id']);
        });

        Schema::table('agent_assignments', function (Blueprint $table) {
            $table->dropForeign(['assigned_by_user_id']);
            $table->dropForeign(['ended_by_user_id']);
            $table->dropIndex(['organization_id', 'status']);
            $table->dropIndex(['agent_user_id', 'status']);
            $table->dropColumn(['status', 'assignment_type', 'starts_at', 'ends_at', 'assigned_by_user_id', 'ended_by_user_id', 'end_reason', 'notes', 'status_changed_at']);
        });
    }
};
