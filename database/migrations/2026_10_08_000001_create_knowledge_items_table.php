<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The business's knowledge base (spec §22): what agents need on a call, and later the AI.
     */
    public function up(): void
    {
        Schema::create('knowledge_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);                              // KnowledgeType
            $table->string('title');
            $table->text('content');
            $table->string('category', 100)->nullable();
            $table->foreignId('service_id')->nullable()->constrained('business_services')->nullOnDelete();
            $table->string('visibility', 20)->default('internal');   // public | internal | team_only
            $table->boolean('is_pinned')->default(false);            // priority: shown first to agents
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'is_active', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_items');
    }
};
