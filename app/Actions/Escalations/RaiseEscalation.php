<?php

namespace App\Actions\Escalations;

use App\Actions\Customers\RecordTimelineEvent;
use App\Actions\Notifications\NotifyOrganization;
use App\Enums\EscalationPriority;
use App\Enums\EscalationType;
use App\Enums\TimelineEventType;
use App\Models\Customer;
use App\Models\Escalation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\EscalationActivity;
use App\Support\Audit\Audit;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Raises an escalation to a business and tells its team at once (spec §25, NTF-03).
 * One active escalation per call, so retries and edits never raise it twice.
 */
class RaiseEscalation
{
    public function __construct(
        private readonly Audit $audit,
        private readonly RecordTimelineEvent $timeline,
        private readonly NotifyOrganization $notify,
    ) {}

    /**
     * @param  array{type: EscalationType|string, priority?: EscalationPriority|string|null, reason: string, details?: ?string, customer_id?: ?int, call_log_id?: ?int, assigned_to_user_id?: mixed}  $data
     *
     * @throws ValidationException when the customer or assignee isn't part of this business
     */
    public function handle(Organization $organization, array $data, ?User $actor = null, string $source = 'manual'): Escalation
    {
        if (! empty($data['call_log_id'])) {
            $existing = Escalation::query()->forOrganization($organization)->active()->where('call_log_id', $data['call_log_id'])->first();
            if ($existing) {
                return $existing;
            }
        }

        $type = $data['type'] instanceof EscalationType ? $data['type'] : EscalationType::from($data['type']);
        $priority = $data['priority'] ?? null;
        $priority = $priority instanceof EscalationPriority ? $priority : (EscalationPriority::tryFrom((string) $priority) ?? $type->defaultPriority());

        $customer = null;
        if (! empty($data['customer_id'])) {
            $customer = Customer::query()->forOrganization($organization)->whereKey($data['customer_id'])->first()
                ?? throw ValidationException::withMessages(['customer_id' => ['Choose one of your customers.']]);
        }
        $assignee = AssignEscalation::member($organization, $data['assigned_to_user_id'] ?? null);

        $escalation = Escalation::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer?->id,
            'call_log_id' => $data['call_log_id'] ?? null,
            'type' => $type,
            'priority' => $priority,
            'reason' => Str::limit(trim($data['reason']), 250, ''),
            'details' => filled($data['details'] ?? null) ? trim((string) $data['details']) : null,
            'source' => $source,
            'raised_by_user_id' => $actor?->id,
            'assigned_to_user_id' => $assignee?->id,
        ]);

        $this->audit->record('escalation.raised', $escalation, new: [
            'type' => $type->value,
            'priority' => $priority->value,
            'reason' => $escalation->reason,
            'source' => $source,
        ], organization: $organization, actor: $actor, label: $escalation->reason);

        if ($customer) {
            $this->timeline->handle($customer, TimelineEventType::Escalation, 'Escalated: '.$type->label(), $escalation->reason, $escalation, ['escalation' => $escalation->ulid, 'priority' => $priority->value], $actor?->id);
        }

        $this->notify->handle($organization, new EscalationActivity($escalation, EscalationActivity::RAISED), 'escalations.view');

        return $escalation;
    }
}
