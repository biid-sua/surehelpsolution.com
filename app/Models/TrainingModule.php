<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A section of a course's draft (spec §20B). */
class TrainingModule extends Model
{
    protected $fillable = ['course_id', 'title', 'description', 'position'];

    /** @return BelongsTo<TrainingCourse, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'course_id');
    }

    /** @return HasMany<TrainingLesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(TrainingLesson::class, 'module_id')->orderBy('position')->orderBy('id');
    }
}
