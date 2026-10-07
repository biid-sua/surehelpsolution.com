<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A business's own website (spec §41B, D44): verified ownership and the latest health check.
 *
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_checked_at
 * @property array{checked_pages?: list<string>, findings?: list<array{key: string, status: string, title: string, detail: string, pages?: list<string>}>}|null $health
 */
class Website extends Model
{
    use BelongsToOrganization;

    /** How often verified sites are checked again. */
    public const RECHECK_DAYS = 30;

    /** Sites a business can connect. */
    public const MAX_PER_BUSINESS = 5;

    public const META_NAME = 'surehelp-site-verification';

    public const DNS_PREFIX = 'surehelp-site-verification=';

    protected $fillable = ['organization_id', 'url', 'host', 'verification_token', 'verified_at', 'verified_via', 'last_checked_at',
        'health_score', 'health', 'last_error', 'added_by'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'health_score' => 'integer',
            'health' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Website $website) {
            $website->ulid ??= (string) Str::ulid();
            $website->verification_token ??= Str::lower(Str::random(32));
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function metaTag(): string
    {
        return '<meta name="'.self::META_NAME.'" content="'.$this->verification_token.'">';
    }

    public function dnsRecord(): string
    {
        return self::DNS_PREFIX.$this->verification_token;
    }

    /**
     * "https://www.example.com/path?x" → ["https://www.example.com/", "www.example.com"], or null when it isn't a public web address.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function normalise(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $input)) {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if ($host === '' || ! str_contains($host, '.') || isset($parts['user']) || isset($parts['port'])
            || ! preg_match('/^[a-z0-9.-]+$/', $host) || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        return [$scheme.'://'.$host.'/', $host];
    }
}
