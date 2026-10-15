<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A business's SureHelp phone line (D60). The business forwards its own number here, or
 * publishes it; calls to it ring the agents who serve the business.
 */
class PhoneNumber extends Model
{
    use BelongsToOrganization;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'organization_id', 'provider', 'provider_sid', 'number', 'label', 'transfer_number', 'record_calls', 'status', 'released_at',
    ];

    protected $attributes = [
        'record_calls' => true,
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'record_calls' => 'boolean',
            'released_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PhoneNumber $number) {
            $number->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @param Builder<PhoneNumber> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /** @return HasMany<VoiceCall, $this> */
    public function calls(): HasMany
    {
        return $this->hasMany(VoiceCall::class);
    }

    /**
     * A phone number as E.164 (+15551234567), or null when it can't be read. The vendor's own
     * E.164 values are kept as they are, even for numbers our validator doesn't know.
     */
    public static function normalize(?string $number): ?string
    {
        $raw = trim((string) $number);

        return Phone::normalize($raw) ?? (preg_match('/^\+\d{8,15}$/', $raw) ? $raw : null);
    }

    /** +15551234567 → (555) 123-4567; other countries in international format. */
    public static function pretty(?string $number): string
    {
        return (string) (Phone::display($number) ?? $number);
    }

    public function display(): string
    {
        return self::pretty($this->number);
    }
}
