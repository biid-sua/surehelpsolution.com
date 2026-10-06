<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent University (spec §20B, D42). Authors edit the draft in the course, module, lesson and
 * question tables; publishing freezes it as an immutable numbered version that learners take.
 * Progress, attempts, completions and certificates always record the version they were for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('training_courses', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            // null = platform-wide; otherwise the company the course belongs to (company-specific training).
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('training_categories')->nullOnDelete();
            $table->string('title', 200);
            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('difficulty', 20)->default('beginner');
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('current_version')->default(0); // 0 = never published
            $table->timestamp('published_at')->nullable();
            $table->timestamp('draft_updated_at')->nullable(); // set when the draft changes, cleared by publishing: "unpublished changes"
            $table->unsignedSmallInteger('valid_for_months')->nullable(); // completion expires after this
            $table->boolean('issues_certificate')->default(false);
            $table->string('certificate_name', 200)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('training_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('training_lessons', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique(); // stable across versions: progress is keyed by it
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('training_modules')->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('type', 20);
            $table->longText('body')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('file_disk', 40)->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('position')->default(0);
            // Quiz lessons only.
            $table->unsignedTinyInteger('pass_percent')->nullable();
            $table->unsignedSmallInteger('max_attempts')->nullable();
            $table->unsignedSmallInteger('question_count')->nullable(); // random subset per attempt
            $table->boolean('shuffle_questions')->default(false);
            $table->timestamps();
        });

        Schema::create('training_questions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lesson_id')->constrained('training_lessons')->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('scenario')->nullable();
            $table->text('prompt');
            $table->text('explanation')->nullable();
            $table->json('options'); // [{id, label, correct}]
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('training_course_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('change_note', 1000)->nullable();
            $table->boolean('retake_required')->default(false);
            $table->longText('content'); // frozen modules, lessons and questions (JSON)
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->unique(['course_id', 'version']);
        });

        Schema::create('training_paths', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('training_path_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('path_id')->constrained('training_paths')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unique(['path_id', 'course_id']);
        });

        // Who must (or may) take a course: everyone, a role, a company's agents, or one agent.
        Schema::create('training_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->string('scope', 20);
            $table->string('role', 60)->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('agent_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->boolean('is_required')->default(true);
            $table->string('priority', 10)->default('normal');
            $table->unsignedSmallInteger('due_days')->nullable();
            $table->string('enforcement', 20)->default('warning'); // company readiness level (D43)
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['scope', 'is_active']);
            $table->index(['organization_id', 'is_active']);
        });

        // One row per agent and course: the current cycle. History lives in completions and certificates.
        Schema::create('training_assignments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete(); // company it was required for
            $table->foreignId('rule_id')->nullable()->constrained('training_rules')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete(); // null = rule or self-enrolled
            $table->boolean('is_required')->default(false);
            $table->string('priority', 10)->default('normal');
            $table->string('status', 20)->default('assigned');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->unsignedInteger('version')->nullable(); // version being taken
            $table->unsignedInteger('required_version')->default(1); // completions below this are outdated
            $table->timestamp('cycle_started_at')->nullable(); // a retake or recertification starts a new cycle
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('completed_version')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->unsignedTinyInteger('best_score')->nullable();
            $table->unsignedInteger('seconds_spent')->default(0);
            $table->unsignedSmallInteger('extra_attempts')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['course_id', 'agent_user_id']);
            $table->index(['agent_user_id', 'status']);
            $table->index(['status', 'due_at']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('training_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('training_assignments')->cascadeOnDelete();
            $table->ulid('lesson_ulid');
            $table->unsignedInteger('course_version');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('seconds_spent')->default(0);
            $table->timestamps();
            $table->unique(['assignment_id', 'course_version', 'lesson_ulid'], 'training_progress_unique');
        });

        Schema::create('training_attempts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('assignment_id')->constrained('training_assignments')->cascadeOnDelete();
            $table->ulid('lesson_ulid');
            $table->unsignedInteger('course_version');
            $table->json('question_ulids');
            $table->json('answers')->nullable();
            $table->json('results')->nullable(); // per question: correct or not
            $table->unsignedTinyInteger('score_percent')->nullable();
            $table->boolean('passed')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['assignment_id', 'lesson_ulid', 'course_version'], 'training_attempts_lookup');
        });

        Schema::create('training_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('training_assignments')->cascadeOnDelete();
            $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->unsignedInteger('course_version');
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedInteger('seconds_spent')->default(0);
            $table->timestamp('completed_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['agent_user_id', 'completed_at']);
        });

        Schema::create('training_certificates', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('number', 40)->unique();
            $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->foreignId('completion_id')->nullable()->constrained('training_completions')->nullOnDelete();
            $table->unsignedInteger('course_version');
            $table->string('name', 200);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['agent_user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        foreach (['training_certificates', 'training_completions', 'training_attempts', 'training_lesson_progress', 'training_assignments',
            'training_rules', 'training_path_courses', 'training_paths', 'training_course_versions', 'training_questions',
            'training_lessons', 'training_modules', 'training_courses', 'training_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
