<?php

namespace App\Models;

use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A post's version for one connected account, and what happened when it was published.
 * Tenant scoping comes through the post and the account.
 *
 * @property array<string, mixed>|null $options
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $published_at
 */
class SocialPostTarget extends Model
{
    use StoresUtc;

    public const PENDING = 'pending';

    public const PUBLISHING = 'publishing';

    public const PUBLISHED = 'published';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'social_post_id', 'social_account_id', 'body', 'options', 'status', 'attempts', 'next_attempt_at',
        'external_id', 'external_url', 'published_at', 'last_error',
    ];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'next_attempt_at' => 'datetime',
            'published_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<SocialPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id')->withoutGlobalScopes();
    }

    /** The text that goes out: this account's own version, or the shared one. */
    public function text(): string
    {
        return filled($this->body) ? (string) $this->body : (string) $this->post->body;
    }
}
