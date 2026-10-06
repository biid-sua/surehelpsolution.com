<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/** A learning path: courses taken in order (brief §1.4), platform-wide or for one company. */
class TrainingPath extends Model
{
    protected $fillable = ['organization_id', 'title', 'description', 'is_active', 'created_by_user_id'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (TrainingPath $path) {
            $path->ulid ??= (string) Str::ulid();
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

    /** @return BelongsToMany<TrainingCourse, $this> */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(TrainingCourse::class, 'training_path_courses', 'path_id', 'course_id')
            ->withPivot('position')->orderByPivot('position');
    }

    /**
     * Same visibility rule as courses: platform-wide, or a company the learner currently serves.
     *
     * @param  Builder<TrainingPath>  $query
     */
    public function scopeAvailableTo(Builder $query, User $user): void
    {
        $query->where('is_active', true);
        if (! $user->isAdmin()) {
            $query->where(fn (Builder $q) => $q->whereNull('organization_id')
                ->orWhereIn('organization_id', $user->assignedOrganizations()->select('organizations.id')));
        }
    }
}
