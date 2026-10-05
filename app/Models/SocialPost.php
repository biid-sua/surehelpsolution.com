<?php

namespace App\Models;

use App\Enums\SocialPostStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One post, published to one or more connected accounts (spec §41). Each account gets a target
 * with its own text (or the shared text) and its own publishing result.
 *
 * @property SocialPostStatus $status
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $published_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 */
class SocialPost extends Model
{
    use BelongsToOrganization, StoresUtc;

    protected $fillable = [
        'organization_id', 'body', 'link_url', 'status', 'scheduled_at', 'published_at', 'source', 'created_by_user_id',
        'created_by_impersonator_id', 'submitted_at', 'approved_by_user_id', 'approved_at', 'review_note',
    ];

    protected $attributes = ['status' => 'draft', 'source' => 'manual'];

    protected function casts(): array
    {
        return [
            'status' => SocialPostStatus::class,
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SocialPost $post) {
            $post->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<SocialPostTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class);
    }

    /** @return BelongsToMany<MediaAsset, $this> */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'social_post_media')->withPivot('position')->orderByPivot('position');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function excerpt(int $length = 80): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', $this->body) ?? ''), $length);
    }
}
