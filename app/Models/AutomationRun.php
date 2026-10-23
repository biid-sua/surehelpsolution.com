<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One automation for one subject (an appointment, customer, call, conversation, escalation or task):
 * when it runs and what happened.
 *
 * @property Carbon $run_at
 * @property Carbon|null $ran_at
 */
class AutomationRun extends Model
{
    use BelongsToOrganization;

    public const SUBJECTS = [
        'appointment' => Appointment::class, 'customer' => Customer::class, 'call' => CallLog::class, 'voice_call' => VoiceCall::class,
        'conversation' => Conversation::class, 'escalation' => Escalation::class, 'task' => Task::class,
    ];

    protected $fillable = ['automation_id', 'organization_id', 'subject_type', 'subject_id', 'status', 'run_at', 'attempts', 'result', 'ran_at'];

    protected function casts(): array
    {
        return ['run_at' => 'datetime', 'ran_at' => 'datetime', 'attempts' => 'integer'];
    }

    /** @return BelongsTo<Automation, $this> */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function subject(): ?Model
    {
        $class = self::SUBJECTS[$this->subject_type] ?? null;

        return $class ? $class::withoutGlobalScopes()->find($this->subject_id) : null;
    }
}
