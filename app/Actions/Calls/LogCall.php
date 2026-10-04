<?php

namespace App\Actions\Calls;

use App\Actions\Customers\MatchOrCreateCustomer;
use App\Actions\Customers\RecordTimelineEvent;
use App\Actions\Notifications\NotifyOrganization;
use App\Enums\CallOwnershipSource;
use App\Enums\NotificationEvent;
use App\Enums\TimelineEventType;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CallActivity;
use App\Support\Audit\Audit;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Records a call handled by an agent. Single implementation for web and API (spec §48).
 *
 * The organization is derived on the server from the selected client and the
 * user needs `calls.create` in it (for agents: only when assigned, docs/decisions.md D3),
 * so an agent can never write into another tenant.
 */
class LogCall
{
    public function __construct(
        private readonly Audit $audit,
        private readonly NotifyOrganization $notify,
        private readonly MatchOrCreateCustomer $customers,
        private readonly RecordTimelineEvent $timeline,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated StoreCallLogRequest data
     *
     * @throws ValidationException when the client is unknown or not assigned to the agent
     */
    public function handle(User $agent, array $data): CallLog
    {
        $organizationId = null;
        $organization = null;
        $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (string) $data['client_id'] : null;

        if ($clientId !== null) {
            $client = User::where('role', 'client')->find($clientId);
            $organization = $client?->primaryOrganization();

            if (! $organization) {
                throw ValidationException::withMessages(['client_id' => ['The selected client was not found.']]);
            }

            if (! $agent->hasPermissionIn('calls.create', $organization)) {
                throw ValidationException::withMessages(['client_id' => ['You are not assigned to this client.']]);
            }

            $organizationId = $organization->getKey();
        }

        $call = CallLog::create([
            'call_id' => CallLog::generateCallId(),
            'client_id' => $clientId,
            'organization_id' => $organizationId,
            'ownership_source' => $organizationId ? CallOwnershipSource::Direct : CallOwnershipSource::Unassigned,
            'call_date' => $data['call_date'],
            'call_time' => $data['call_time'],
            'caller_name' => $data['caller_name'] ?? null,
            'caller_phone' => $data['caller_phone'] ?? null,
            'caller_email' => $data['caller_email'] ?? null,
            'reason_for_call' => $data['reason_for_call'],
            'call_outcome' => $data['call_outcome'],
            'agent_name' => $data['agent_name'],
            'status' => $data['status'],
            'service_request' => (bool) ($data['service_request'] ?? false),
            'service_date' => $data['service_date'] ?? null,
            'service_window' => $data['service_window'] ?? null,
            'service_location' => $data['service_location'] ?? null,
            'notes' => $data['notes'] ?? null,
            'user_id' => $agent->getKey(),
        ]);

        $this->audit->record('call.created', $call, new: [
            'call_outcome' => $call->call_outcome,
            'status' => $call->status,
            'service_request' => $call->service_request,
        ], actor: $agent);

        if ($organization) {
            $this->linkCustomer($call, $organization, $agent);
            $this->notify->handle($organization, new CallActivity($call, self::eventFor($call)), 'calls.view');
        }

        return $call;
    }

    /**
     * Link the call to a customer (match by phone, then email, else create) and add it to their timeline.
     * A failure here must never lose the call itself, so it is reported, not thrown.
     */
    private function linkCustomer(CallLog $call, Organization $organization, User $agent): void
    {
        try {
            $match = $this->customers->handle($organization, [
                'name' => $call->caller_name,
                'phone' => $call->caller_phone,
                'email' => $call->caller_email,
                'address' => $call->service_location,
            ], 'call', $agent->getKey());

            if (! $match) {
                return;
            }

            $customer = $match['customer'];
            $call->forceFill(['customer_id' => $customer->id])->saveQuietly();

            if ($match['created']) {
                $this->timeline->handle($customer, TimelineEventType::CustomerCreated, 'Added from a call', actorId: $agent->getKey(), occurredAt: $call->created_at);
            }

            $this->timeline->handle(
                $customer,
                TimelineEventType::CallIncoming,
                'Incoming call · '.Str::headline((string) $call->reason_for_call),
                trim('Outcome: '.$call->statusLabel().'. '.(string) $call->notes),
                $call,
                ['call_id' => $call->call_id, 'outcome' => $call->call_outcome],
                $agent->getKey(),
                $call->created_at,
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Which notification a call triggers (spec §27).
     */
    public static function eventFor(CallLog $call): NotificationEvent
    {
        return match ($call->call_outcome) {
            'call-dropped', 'no-response' => NotificationEvent::CallMissed,
            'callback-requested', 'followup-scheduled' => NotificationEvent::FollowUpCreated,
            default => NotificationEvent::CallLogged,
        };
    }
}
