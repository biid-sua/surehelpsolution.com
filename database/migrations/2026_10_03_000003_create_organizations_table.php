<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenancy foundation (docs/implementation-plan.md P1-2).
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique(); // public identifier, never expose the numeric id
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 20)->default('onboarding')->index();
            $table->string('timezone', 64)->nullable(); // IANA, e.g. America/Chicago; asked at onboarding
            $table->char('currency', 3)->default('USD');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('staff'); // owner | manager | staff (config/authorization.php)
            $table->string('status', 20)->default('active');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('agent_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->string('source', 20)->default('manual');
            $table->timestamps();

            $table->unique(['organization_id', 'agent_user_id']);
            $table->index('agent_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_assignments');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
