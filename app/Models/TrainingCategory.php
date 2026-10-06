<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A shelf in Agent University (spec §20B): Customer service, Security, Company procedures… */
class TrainingCategory extends Model
{
    protected $fillable = ['name', 'slug', 'sort_order'];

    protected static function booted(): void
    {
        static::creating(function (TrainingCategory $category) {
            $category->slug ??= Str::slug($category->name).'-'.Str::lower(Str::random(4));
        });
    }

    /** @return HasMany<TrainingCourse, $this> */
    public function courses(): HasMany
    {
        return $this->hasMany(TrainingCourse::class, 'category_id');
    }
}
