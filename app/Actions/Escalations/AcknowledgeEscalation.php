<?php

namespace App\Actions\Escalations;

use App\Enums\EscalationStatus;
use App\Models\Escalation;
use App\Models\User;
use App\Support\Audit\Audit;

/**
 * "I've seen it and I'm on it." Stops the unanswered-escalation reminder.
 */
class AcknowledgeEscalation
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(Escalation $escalation, User $actor): Escalation
    {
        if ($escalation->status !== EscalationStatus::Open) {
            return $escalation;
        }

        $escalation->forceFill([
            'status' => EscalationStatus::Acknowledged,
            'acknowledged_at' => now(),
            'acknowledged_by_user_id' => $actor->id,
            'assigned_to_user_id' => $escalation->assigned_to_user_id ?? $actor->id,
        ])->save();

        $this->audit->record('escalation.acknowledged', $escalation, old: ['status' => 'open'], new: ['status' => 'acknowledged'], actor: $actor, label: $escalation->reason);

        return $escalation;
    }
}
