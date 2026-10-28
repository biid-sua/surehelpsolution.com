<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A website we host for a business (D99): one per business, at `{subdomain}.{sites.domain}` (or
 * `/site/{subdomain}` until that domain is set up). The newest version is the draft; visitors see the
 * published one.
 *
 * @property Carbon|null $published_at
 * @property string|null $custom_domain the business's own domain (D101)
 * @property string|null $domain_status pending, active or failed
 * @property Carbon|null $domain_checked_at
 * @property Carbon|null $domain_active_at
 */
class Site extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'subdomain', 'template', 'published_version_id', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'domain_checked_at' => 'datetime', 'domain_active_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Site $site) => $site->ulid ??= (string) Str::ulid());
    }

    /** @return HasMany<SiteVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(SiteVersion::class);
    }

    /** @return BelongsTo<SiteVersion, $this> */
    public function published(): BelongsTo
    {
        return $this->belongsTo(SiteVersion::class, 'published_version_id');
    }

    public function draft(): SiteVersion
    {
        /** @var SiteVersion */
        return $this->versions()->withoutGlobalScopes()->orderByDesc('number')->firstOrFail();
    }

    /** The draft has changes visitors can't see yet. */
    public function hasUnpublishedChanges(): bool
    {
        return $this->published_version_id !== $this->draft()->id;
    }

    /** The business's own domain, once it works (D101). */
    public function liveDomain(): ?string
    {
        return $this->domain_status === 'active' ? $this->custom_domain : null;
    }

    public function url(string $page = ''): string
    {
        if ($own = $this->liveDomain()) {
            return 'https://'.$own.($page !== '' ? '/'.$page : '');
        }
        $domain = config('sites.domain');

        return $domain
            ? 'https://'.$this->subdomain.'.'.$domain.($page !== '' ? '/'.$page : '')
            : route('sites.path', array_filter(['subdomain' => $this->subdomain, 'page' => $page]));
    }
}
