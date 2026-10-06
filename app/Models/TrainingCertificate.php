<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A certificate earned by completing a course (brief §1.11–1.12). Stored statuses are active and
 * revoked; "expiring soon" and "expired" follow from expires_at.
 *
 * @property Carbon $issued_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 */
class TrainingCertificate extends Model
{
    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    /** "Expiring soon" starts this many days before expiry. */
    public const EXPIRING_DAYS = 30;

    protected $fillable = [
        'number', 'agent_user_id', 'course_id', 'completion_id', 'course_version', 'name', 'issued_at', 'expires_at', 'status',
        'revoked_at', 'revoked_by_user_id', 'revoke_reason',
    ];

    protected $attributes = ['status' => self::ACTIVE];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (TrainingCertificate $certificate) {
            $certificate->ulid ??= (string) Str::ulid();
            $certificate->number ??= 'SHC-'.now()->format('Y').'-'.Str::upper(Str::random(8));
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    /** @return BelongsTo<TrainingCourse, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'course_id');
    }

    public function effectiveStatus(): string
    {
        if ($this->status === self::REVOKED) {
            return 'revoked';
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'expired';
        }
        if ($this->expires_at && $this->expires_at->lte(now()->addDays(self::EXPIRING_DAYS))) {
            return 'expiring_soon';
        }

        return 'active';
    }

    public function label(): string
    {
        return ['active' => 'Active', 'expiring_soon' => 'Expiring soon', 'expired' => 'Expired', 'revoked' => 'Revoked'][$this->effectiveStatus()];
    }

    public function tone(): string
    {
        return ['active' => 'success', 'expiring_soon' => 'warning', 'expired' => 'danger', 'revoked' => 'neutral'][$this->effectiveStatus()];
    }
}
