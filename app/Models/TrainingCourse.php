<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A course in Agent University (spec §20B, D42): platform-wide (organization_id null) or owned by
 * one company. The modules, lessons and questions are the editable draft; learners take the
 * published version (current_version).
 *
 * @property Carbon|null $published_at
 * @property Carbon|null $draft_updated_at
 */
class TrainingCourse extends Model
{
    public const DIFFICULTIES = ['beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'];

    protected $fillable = [
        'organization_id', 'category_id', 'title', 'summary', 'description', 'thumbnail_path', 'difficulty', 'estimated_minutes',
        'owner_user_id', 'is_active', 'valid_for_months', 'issues_certificate', 'certificate_name', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected $attributes = ['difficulty' => 'beginner', 'is_active' => true, 'current_version' => 0, 'issues_certificate' => false];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'issues_certificate' => 'boolean',
            'published_at' => 'datetime',
            'draft_updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TrainingCourse $course) {
            $course->ulid ??= (string) Str::ulid();
            $course->draft_updated_at ??= now();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<TrainingCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TrainingCategory::class, 'category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<TrainingModule, $this> */
    public function modules(): HasMany
    {
        return $this->hasMany(TrainingModule::class, 'course_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<TrainingLesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(TrainingLesson::class, 'course_id');
    }

    /** @return HasMany<TrainingCourseVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TrainingCourseVersion::class, 'course_id')->orderByDesc('version');
    }

    /** @return HasMany<TrainingRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(TrainingRule::class, 'course_id');
    }

    /** @return HasMany<TrainingAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(TrainingAssignment::class, 'course_id');
    }

    public function version(int $number): ?TrainingCourseVersion
    {
        return $this->versions()->where('version', $number)->first();
    }

    public function isPublished(): bool
    {
        return $this->current_version > 0;
    }

    public function isPlatformWide(): bool
    {
        return $this->organization_id === null;
    }

    public function hasUnpublishedChanges(): bool
    {
        return ! $this->isPublished() || $this->draft_updated_at !== null;
    }

    /** Records that the draft changed, so authors see "unpublished changes". */
    public function touchDraft(?User $by = null): void
    {
        $this->forceFill(['draft_updated_at' => now(), 'updated_by_user_id' => $by->id ?? $this->updated_by_user_id])->save();
    }

    public function audienceLabel(): string
    {
        return $this->organization_id ? ($this->organization->name ?? 'Company') : 'All agents';
    }

    /**
     * Published, active courses a learner may open: platform-wide ones, and company courses only
     * for companies they currently serve (D40). Platform staff see every course.
     *
     * @param  Builder<TrainingCourse>  $query
     */
    public function scopeAvailableTo(Builder $query, User $user): void
    {
        $query->where('training_courses.is_active', true)->where('training_courses.current_version', '>', 0);
        if (! $user->isAdmin()) {
            $query->where(fn (Builder $q) => $q->whereNull('training_courses.organization_id')
                ->orWhereIn('training_courses.organization_id', $user->assignedOrganizations()->select('organizations.id')));
        }
    }
}
