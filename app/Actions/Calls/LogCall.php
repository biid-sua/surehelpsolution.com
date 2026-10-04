<?php

namespace App\Actions\Calls;

use App\Actions\Notifications\NotifyOrganization;
use App\Enums\CallOwnershipSource;
use App\Enums\NotificationEvent;
use App\Models\CallLog;
use App\Models\User;
use App\Notifications\CallActivity;
use App\Support\Audit\Audit;
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
            $this->notify->handle($organization, new CallActivity($call, self::eventFor($call)), 'calls.view');
        }

        return $call;
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
