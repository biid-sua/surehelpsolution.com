<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A one-off message to a group of the business's past customers (D116), by text and/or email.
 * Statuses: draft → sending → sent (docs/state-machines.md).
 *
 * @property string $ulid
 * @property array{inactive_months?: int|null, tag_id?: int|null, service_id?: int|null} $audience
 * @property list<string> $channels
 * @property Carbon|null $sent_at
 */
class Campaign extends Model
{
    use BelongsToOrganization;

    public const DRAFT = 'draft';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    protected $fillable = ['organization_id', 'created_by_user_id', 'name', 'status', 'audience', 'channels', 'text_body', 'email_subject', 'email_body',
        'recipients_count', 'sent_count', 'sent_at'];

    protected function casts(): array
    {
        return ['audience' => 'array', 'channels' => 'array', 'recipients_count' => 'integer', 'sent_count' => 'integer', 'sent_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Campaign $campaign) {
            $campaign->ulid ??= (string) Str::ulid();
        });
    }

    /** @return HasMany<CampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }
}
