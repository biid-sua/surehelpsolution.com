<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One piece of SEO work done with AI for a business (D57): what was asked, what came back, the
 * tokens it used, whether the business approved it and when it was put live on the site.
 *
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property Carbon|null $applied_at
 */
class SeoTask extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['queued', 'running', 'done', 'failed'];

    protected $fillable = ['organization_id', 'website_id', 'type', 'status', 'source', 'input', 'output', 'model', 'input_tokens', 'output_tokens',
        'error', 'review', 'applied_at', 'applied_by_user_id', 'requested_by_user_id'];

    protected $attributes = ['status' => 'queued', 'source' => 'client'];

    protected function casts(): array
    {
        return ['input' => 'array', 'output' => 'array', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'applied_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (SeoTask $t) => $t->ulid ??= (string) Str::ulid());
    }

    public function tokens(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'failed'], true);
    }

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }
}
