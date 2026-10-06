<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A published, frozen version of a course (D42). Learners always take a version, never the draft.
 *
 * @property array{title: string, summary: ?string, description: ?string, modules: list<array{title: string, description: ?string, lessons: list<array<string, mixed>>}>} $content
 * @property Carbon $published_at
 */
class TrainingCourseVersion extends Model
{
    public $timestamps = false;

    protected $fillable = ['course_id', 'version', 'change_note', 'retake_required', 'content', 'published_by_user_id', 'published_at'];

    protected function casts(): array
    {
        return ['content' => 'array', 'retake_required' => 'boolean', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<TrainingCourse, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'course_id');
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * Every lesson in order, each with its module title and position.
     *
     * @return list<array<string, mixed>>
     */
    public function lessons(): array
    {
        $lessons = [];
        foreach ($this->content['modules'] as $m => $module) {
            foreach ($module['lessons'] as $lesson) {
                $lessons[] = $lesson + ['module' => $module['title'], 'module_index' => $m];
            }
        }

        return $lessons;
    }

    /** @return array<string, mixed>|null */
    public function lesson(string $ulid): ?array
    {
        foreach ($this->lessons() as $lesson) {
            if ($lesson['ulid'] === $ulid) {
                return $lesson;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function lessonUlids(): array
    {
        return array_column($this->lessons(), 'ulid');
    }
}
