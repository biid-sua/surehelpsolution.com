<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A customer review of the business, from Google (D83).
 *
 * @property Carbon $reviewed_at
 * @property Carbon|null $replied_at
 * @property Carbon|null $drafted_at
 */
class Review extends Model
{
    use BelongsToOrganization, StoresUtc;

    public const NEW = 'new';

    public const REPLIED = 'replied';

    public const DISMISSED = 'dismissed';

    protected $fillable = [
        'organization_id', 'social_account_id', 'source', 'external_id', 'author_name', 'rating', 'comment', 'reviewed_at',
        'reply', 'replied_at', 'replied_by_user_id', 'draft', 'drafted_at', 'status', 'error',
    ];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'reviewed_at' => 'datetime', 'replied_at' => 'datetime', 'drafted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Review $review) {
            $review->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by_user_id');
    }

    /** @param Builder<Review> $query */
    public function scopeNeedsReply(Builder $query): void
    {
        $query->where('status', self::NEW);
    }

    public function authorLabel(): string
    {
        return $this->author_name ?: 'A Google user';
    }
}
