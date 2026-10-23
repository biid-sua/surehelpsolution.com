<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Services\Automation\Flow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * "When this happens, wait, then do that" for one business (spec §42–43, D50), with the wider set of
 * triggers, actions and conditions of Automations 2.0 (D80).
 *
 * @property array<string, mixed>|null $conditions
 * @property array<string, mixed>|null $action_config
 * @property list<array<string, mixed>>|null $steps a flow (D81): wait, action and branch steps; null runs the single action
 */
class Automation extends Model
{
    use BelongsToOrganization;

    /** What can start an automation (D50, D80), grouped for the picker. */
    public const TRIGGER_GROUPS = [
        'Appointments' => [
            'appointment_booked' => 'An appointment is booked',
            'appointment_upcoming' => 'An appointment is coming up',
            'appointment_rescheduled' => 'An appointment is moved',
            'appointment_completed' => 'An appointment is marked completed',
            'appointment_no_show' => 'A customer doesn\'t show up',
            'appointment_cancelled' => 'An appointment is cancelled',
        ],
        'Calls and messages' => [
            'call_logged' => 'A call is answered and logged',
            'call_missed' => 'A call is missed',
            'conversation_started' => 'Someone messages you for the first time',
        ],
        'Customers and work' => [
            'customer_created' => 'A new customer or lead is added',
            'customer_inactive' => 'A customer hasn\'t been back for a while',
            'escalation_raised' => 'An escalation is raised',
            'task_overdue' => 'A task becomes overdue',
            'review_received' => 'A new Google review arrives',
        ],
    ];

    public const TRIGGERS = [
        ...self::TRIGGER_GROUPS['Appointments'], ...self::TRIGGER_GROUPS['Calls and messages'], ...self::TRIGGER_GROUPS['Customers and work'],
    ];

    /** What each trigger is about (the run's subject). */
    public const SUBJECT_FOR = [
        'appointment_booked' => 'appointment', 'appointment_upcoming' => 'appointment', 'appointment_rescheduled' => 'appointment',
        'appointment_completed' => 'appointment', 'appointment_no_show' => 'appointment', 'appointment_cancelled' => 'appointment',
        'call_logged' => 'call', 'call_missed' => 'voice_call', 'conversation_started' => 'conversation',
        'customer_created' => 'customer', 'customer_inactive' => 'customer', 'escalation_raised' => 'escalation', 'task_overdue' => 'task',
        'review_received' => 'review',
    ];

    public const ACTIONS = [
        'send_email' => 'Email the customer',
        'send_text' => 'Text the customer',
        'create_task' => 'Create a task for your team',
        'notify_team' => 'Notify your team',
        'email_address' => 'Email someone else',
        'add_tag' => 'Add a tag to the customer',
        'remove_tag' => 'Remove a tag from the customer',
        'set_status' => 'Change the customer\'s status',
    ];

    /** Optional checks, all of which must hold (D80): field => [label, operators, value kind]. */
    public const CONDITION_FIELDS = [
        'customer_tag' => ['label' => 'Customer tag', 'ops' => ['has' => 'is tagged', 'lacks' => 'isn\'t tagged'], 'value' => 'tag'],
        'customer_status' => ['label' => 'Customer status', 'ops' => ['is' => 'is', 'not' => 'isn\'t'], 'value' => 'status'],
        'first_visit' => ['label' => 'First visit', 'ops' => ['yes' => 'is their first', 'no' => 'isn\'t their first'], 'value' => null],
        'has_email' => ['label' => 'Customer email', 'ops' => ['yes' => 'is known', 'no' => 'isn\'t known'], 'value' => null],
        'has_phone' => ['label' => 'Customer phone', 'ops' => ['yes' => 'is known', 'no' => 'isn\'t known'], 'value' => null],
        'call_outcome' => ['label' => 'Call outcome', 'ops' => ['is' => 'is', 'not' => 'isn\'t'], 'value' => 'outcome'],
        'business_hours' => ['label' => 'It happened', 'ops' => ['open' => 'while you\'re open', 'closed' => 'while you\'re closed'], 'value' => null],
        'review_rating' => ['label' => 'The review', 'ops' => ['low' => 'has 1 or 2 stars', 'mid' => 'has 3 stars', 'high' => 'has 4 or 5 stars'], 'value' => null],
        // Only in a flow's "If" step (D81): "since" is when the flow started.
        'replied' => ['label' => 'A reply from them', 'ops' => ['yes' => 'came in since the flow started', 'no' => 'hasn\'t come in yet'], 'value' => null, 'branch_only' => true],
        'booked_since' => ['label' => 'A new booking', 'ops' => ['yes' => 'was made since the flow started', 'no' => 'hasn\'t been made yet'], 'value' => null, 'branch_only' => true],
    ];

    /** Placeholders the email action fills in. */
    public const PLACEHOLDERS = ['first_name', 'business', 'service', 'date', 'time', 'phone', 'review_link', 'booking_link', 'reason'];

    /** The trigger's wait counts back from the appointment instead of forward from the event. */
    public function waitsBefore(): bool
    {
        return $this->trigger === 'appointment_upcoming';
    }

    /**
     * The flow to run. An automation saved before flows is one action step.
     *
     * @return list<array<string, mixed>>
     */
    public function flow(): array
    {
        return $this->steps ?: [['type' => 'action', 'action' => $this->action, 'config' => $this->action_config ?? []]];
    }

    public function isFlow(): bool
    {
        return ! empty($this->steps);
    }

    public function stepCount(): int
    {
        return iterator_count(Flow::walk($this->flow()));
    }

    /** @return list<array{field: string, op: string, value?: string}> */
    public function rules(): array
    {
        return array_values(array_filter((array) $this->condition('rules', []), 'is_array'));
    }

    public const MAX_DELAY_MINUTES = 60 * 24 * 30;

    protected $fillable = ['organization_id', 'name', 'trigger', 'conditions', 'delay_minutes', 'action', 'action_config', 'steps', 'is_active', 'created_by_user_id'];

    /** Limits for a flow (D81). */
    public const MAX_STEPS = 15;

    public const MAX_DEPTH = 2;

    protected function casts(): array
    {
        return ['conditions' => 'array', 'action_config' => 'array', 'steps' => 'array', 'is_active' => 'boolean', 'delay_minutes' => 'integer'];
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
        $word = $this->waitsBefore() ? ' before' : ' later';

        return match (true) {
            $m === 0 => $this->waitsBefore() ? 'at the start time' : 'straight away',
            $m % 1440 === 0 => ($m / 1440).' '.Str::plural('day', $m / 1440).$word,
            $m % 60 === 0 => ($m / 60).' '.Str::plural('hour', $m / 60).$word,
            default => $m.' minutes'.$word,
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
