<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Something the business taught its assistant (D39). Only active guidelines reach the AI.
 *
 * @property Carbon|null $approved_at
 */
class AiGuideline extends Model
{
    use BelongsToOrganization, StoresUtc;

    protected $fillable = ['organization_id', 'text', 'status', 'source_message_id', 'created_by_user_id', 'approved_by_user_id', 'approved_at'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (AiGuideline $guideline) {
            $guideline->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
