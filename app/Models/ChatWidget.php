<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A business's website chat widget (D37): its public key, look and the sites allowed to show it.
 *
 * @property list<string>|null $allowed_origins
 * @property array<string, bool>|null $features
 */
class ChatWidget extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'public_key', 'is_enabled', 'title', 'greeting', 'color', 'allowed_origins', 'features', 'bookings_need_confirmation'];

    /** What the one snippet can add to a website (spec §41B, D44). Chat is on unless switched off. */
    public const FEATURES = [
        'chat' => 'Chat',
        'booking' => 'Online booking',
        'call' => 'Click to call',
        'lead' => 'Contact form',
    ];

    protected $attributes = ['is_enabled' => true, 'title' => 'Chat with us', 'color' => '#7C3AED', 'bookings_need_confirmation' => true];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'allowed_origins' => 'array', 'features' => 'array', 'bookings_need_confirmation' => 'boolean'];
    }

    public static function for(Organization $organization): self
    {
        return self::query()->forOrganization($organization)->first()
            ?? self::create(['organization_id' => $organization->id, 'public_key' => 'shw_'.Str::lower(Str::random(32))]);
    }

    public function offers(string $feature): bool
    {
        return (bool) ((($this->features ?? []) + ['chat' => true])[$feature] ?? false);
    }

    /**
     * @return array<string, bool>
     */
    public function enabledFeatures(): array
    {
        return array_map(fn (string $f) => $this->offers($f), array_combine(array_keys(self::FEATURES), array_keys(self::FEATURES)));
    }

    /** An empty list allows any site; otherwise the page's origin must be one of them (or a subdomain). */
    public function allowsOrigin(?string $origin): bool
    {
        $allowed = array_filter((array) $this->allowed_origins);
        if ($allowed === []) {
            return true;
        }
        if (! $origin) {
            return false;
        }

        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
        foreach ($allowed as $entry) {
            $entryHost = strtolower((string) (parse_url(str_contains($entry, '://') ? $entry : 'https://'.$entry, PHP_URL_HOST) ?: $entry));
            if ($host !== '' && ($host === $entryHost || str_ends_with($host, '.'.$entryHost))) {
                return true;
            }
        }

        return false;
    }

    public function snippet(): string
    {
        return '<script src="'.route('chat.widget-js').'" data-surehelp-chat="'.$this->public_key.'" async></script>';
    }
}
