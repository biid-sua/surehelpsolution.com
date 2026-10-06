<?php

namespace App\Models;

use App\Enums\LessonType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A lesson in a course's draft (spec §20B, D42). Its ulid is stable across versions, so progress
 * on an unchanged lesson is recognised. Files are private; published versions keep pointing at
 * the file they were published with, so a file is never deleted while a version may use it.
 *
 * @property LessonType $type
 */
class TrainingLesson extends Model
{
    protected $fillable = [
        'course_id', 'module_id', 'title', 'type', 'body', 'url', 'file_disk', 'file_path', 'file_name', 'file_mime', 'file_size',
        'duration_minutes', 'position', 'pass_percent', 'max_attempts', 'question_count', 'shuffle_questions',
    ];

    protected function casts(): array
    {
        return ['type' => LessonType::class, 'shuffle_questions' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (TrainingLesson $lesson) {
            $lesson->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<TrainingCourse, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'course_id');
    }

    /** @return BelongsTo<TrainingModule, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(TrainingModule::class, 'module_id');
    }

    /** @return HasMany<TrainingQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(TrainingQuestion::class, 'lesson_id')->orderBy('position')->orderBy('id');
    }
}
