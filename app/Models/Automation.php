<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * "When this happens, wait, then do that" for one business (spec §42–43, D50).
 *
 * @property array<string, mixed>|null $conditions
 * @property array<string, mixed>|null $action_config
 */
class Automation extends Model
{
    use BelongsToOrganization;

    public const TRIGGERS = [
        'appointment_completed' => 'An appointment is marked completed',
        'appointment_cancelled' => 'An appointment is cancelled',
        'customer_created' => 'A new customer or lead is added',
    ];

    public const ACTIONS = [
        'send_email' => 'Email the customer',
        'create_task' => 'Create a task for your team',
        'notify_team' => 'Notify your team',
    ];

    /** Placeholders the email action fills in. */
    public const PLACEHOLDERS = ['first_name', 'business', 'service', 'date', 'time', 'phone', 'review_link'];

    public const MAX_DELAY_MINUTES = 60 * 24 * 30;

    protected $fillable = ['organization_id', 'name', 'trigger', 'conditions', 'delay_minutes', 'action', 'action_config', 'is_active', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'action_config' => 'array', 'is_active' => 'boolean', 'delay_minutes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Automation $a) => $a->ulid ??= (string) Str::ulid());
    }

    public function condition(string $key, mixed $default = null): mixed
    {
        return ($this->conditions ?? [])[$key] ?? $default;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return ($this->action_config ?? [])[$key] ?? $default;
    }

    public function delayLabel(): string
    {
        $m = $this->delay_minutes;

        return match (true) {
            $m === 0 => 'straight away',
            $m % 1440 === 0 => ($m / 1440).' '.Str::plural('day', $m / 1440).' later',
            $m % 60 === 0 => ($m / 60).' '.Str::plural('hour', $m / 60).' later',
            default => $m.' minutes later',
        };
    }

    /** @return HasMany<AutomationRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
