<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved state of a hosted website (D99). `content` follows App\Services\Sites\SiteContent.
 * `source`: template, ai, restore.
 *
 * @property array<string, mixed> $content
 */
class SiteVersion extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['site_id', 'organization_id', 'number', 'content', 'note', 'source', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['content' => 'array', 'number' => 'integer'];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
