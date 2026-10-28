<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a business's AI credit balance (D100). `kind`: purchase, grant (staff, either sign),
 * usage (negative: a website change or SEO task beyond its monthly allowance).
 */
class CreditEntry extends Model
{
    use BelongsToOrganization;

    public const KINDS = ['purchase', 'grant', 'usage'];

    protected $fillable = ['organization_id', 'credits', 'kind', 'note', 'credit_purchase_id', 'site_edit_id', 'seo_task_id', 'user_id'];

    protected function casts(): array
    {
        return ['credits' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
