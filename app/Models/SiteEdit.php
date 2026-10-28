<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change to a hosted website asked for in plain words (D99), done by AI in the background.
 * Status: queued → running → done (a new draft version), nothing (no change needed) or failed.
 */
class SiteEdit extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['queued', 'running', 'done', 'nothing', 'failed'];

    protected $fillable = ['site_id', 'organization_id', 'prompt', 'page', 'status', 'summary', 'error', 'version_id', 'model', 'input_tokens', 'output_tokens', 'user_id'];

    protected $attributes = ['status' => 'queued', 'page' => '', 'input_tokens' => 0, 'output_tokens' => 0];

    protected function casts(): array
    {
        return ['input_tokens' => 'integer', 'output_tokens' => 'integer'];
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
