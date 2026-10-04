<?php

namespace App\Actions\Escalations;

use App\Actions\Customers\RecordTimelineEvent;
use App\Enums\EscalationStatus;
use App\Enums\TimelineEventType;
use App\Models\Escalation;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Validation\ValidationException;

/**
 * Closes an escalation with a note saying what was done (spec §25: resolution notes).
 */
class ResolveEscalation
{
    public function __construct(
        private readonly Audit $audit,
        private readonly RecordTimelineEvent $timeline,
    ) {}

    /**
     * @throws ValidationException without a resolution note
     */
    public function handle(Escalation $escalation, User $actor, string $notes): Escalation
    {
        $notes = trim($notes);
        if ($notes === '') {
            throw ValidationException::withMessages(['resolution_notes' => ['Say briefly what was done.']]);
        }

        if ($escalation->status === EscalationStatus::Resolved) {
            return $escalation;
        }

        $old = $escalation->status;
        $escalation->forceFill([
            'status' => EscalationStatus::Resolved,
            'resolved_at' => now(),
            'resolved_by_user_id' => $actor->id,
            'resolution_notes' => $notes,
            'acknowledged_at' => $escalation->acknowledged_at ?? now(),
            'acknowledged_by_user_id' => $escalation->acknowledged_by_user_id ?? $actor->id,
        ])->save();

        $this->audit->record('escalation.resolved', $escalation, old: ['status' => $old->value], new: ['status' => 'resolved'], actor: $actor, label: $escalation->reason);

        if ($escalation->customer) {
            $this->timeline->handle($escalation->customer, TimelineEventType::Escalation, 'Escalation resolved', $notes, $escalation, ['escalation' => $escalation->ulid], $actor->id);
        }

        return $escalation;
    }
}
