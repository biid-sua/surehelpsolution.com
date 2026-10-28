<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Websites we host for businesses (D99). Every change is a numbered version: the newest is the draft,
 * `published_version_id` is what visitors see, and any older version can be restored. Edits asked
 * for in plain words are queued, and record the AI tokens they used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('subdomain', 40)->unique();
            $table->string('template', 30);
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('site_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->longText('content');
            $table->string('note', 200);
            $table->string('source', 20);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['site_id', 'number']);
        });

        Schema::create('site_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->string('page', 40)->default('');
            $table->string('status', 20)->default('queued');
            $table->string('summary', 500)->nullable();
            $table->string('error', 300)->nullable();
            $table->foreignId('version_id')->nullable()->constrained('site_versions')->nullOnDelete();
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_edits');
        Schema::dropIfExists('site_versions');
        Schema::dropIfExists('sites');
    }
};
