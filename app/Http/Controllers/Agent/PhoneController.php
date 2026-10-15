<?php

namespace App\Http\Controllers\Agent;

use App\Exceptions\TelephonyUnavailable;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\Telephony\AgentPresence;
use App\Services\Telephony\CallRouter;
use App\Services\Telephony\TelephonyProvider;
use App\Services\Telephony\VoiceCalls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The agent's browser phone (D60): readiness, check-ins, the calls ringing for them, answering,
 * transfer, and (without Twilio) simulated calls for demos and training.
 */
class PhoneController extends Controller
{
    public function __construct(
        private readonly TelephonyProvider $provider,
        private readonly AgentPresence $presence,
        private readonly VoiceCalls $calls,
    ) {}

    /** A token for the vendor's browser phone. */
    public function token(Request $request): JsonResponse
    {
        $agent = $request->user();
        abort_unless($this->provider->usesBrowserSdk() && $this->provider->isConfigured(), 404);

        return response()->json([
            'token' => $this->provider->accessToken(AgentPresence::identity($agent)),
            'identity' => AgentPresence::identity($agent),
            'ttl' => 3600,
        ]);
    }

    public function presence(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([AgentPresence::AVAILABLE, AgentPresence::AWAY, AgentPresence::OFFLINE])]]);
        $agent = $request->user();
        abort_if($agent->phone_status === AgentPresence::BUSY && $this->activeCall($agent) !== null, 409, 'Finish the call first.');
        $this->presence->set($agent, $data['status']);

        return $this->state($agent->fresh());
    }

    /** Every few seconds while the portal is open: keeps the agent online and returns what's ringing. */
    public function checkIn(Request $request): JsonResponse
    {
        $agent = $request->user();
        if ($agent->phone_status !== AgentPresence::OFFLINE) {
            $this->presence->checkIn($agent);
        }

        return $this->state($agent->fresh());
    }

    public function answer(Request $request, VoiceCall $voiceCall): JsonResponse
    {
        $agent = $request->user();
        $this->authorizeCall($voiceCall, $agent);
        if ($voiceCall->answered_by_user_id !== $agent->id && ! $this->calls->answer($voiceCall, $agent)) {
            return response()->json(['message' => 'Someone else answered this call.'], 409);
        }

        return response()->json(['call' => $this->describe($voiceCall->refresh())]);
    }

    /** Demo phone only: the agent hangs up (Twilio reports this itself). */
    public function hangUp(Request $request, VoiceCall $voiceCall): JsonResponse
    {
        abort_if($this->provider->usesBrowserSdk(), 404);
        $agent = $request->user();
        $this->authorizeCall($voiceCall, $agent);
        $this->calls->ended($voiceCall);

        return $this->state($agent->fresh());
    }

    public function transfer(Request $request, VoiceCall $voiceCall): JsonResponse
    {
        $agent = $request->user();
        $this->authorizeCall($voiceCall, $agent);
        try {
            $this->calls->transfer($voiceCall, $agent);
        } catch (TelephonyUnavailable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if (! $this->provider->usesBrowserSdk()) {
            $this->calls->ended($voiceCall); // the demo owner "answers" at once
        }

        return response()->json(['message' => 'Transferred to '.PhoneNumber::pretty($voiceCall->transferred_to).'.']);
    }

    /** Demo phone only: ring this agent (and anyone else available) with a call to a business's line. */
    public function simulate(Request $request, CallRouter $router): JsonResponse
    {
        abort_if($this->provider->usesBrowserSdk(), 404);
        $agent = $request->user();
        $data = $request->validate(['organization' => ['required', 'string']]);
        $organization = Organization::query()->where('ulid', $data['organization'])->first();
        abort_unless($organization && $agent->hasPermissionIn('calls.create', $organization), 404);
        $line = PhoneNumber::query()->forOrganization($organization)->active()->first();
        if (! $line) {
            return response()->json(['message' => 'This business has no phone line yet. Add one under Admin › Phone lines.'], 422);
        }
        if ($this->presence->status($agent) !== AgentPresence::AVAILABLE) {
            $this->presence->set($agent, AgentPresence::AVAILABLE);
        }

        // Half the time a known customer calls, so the caller's record pops up.
        $customer = random_int(0, 1) ? Customer::query()->forOrganization($organization)->whereNotNull('phone_e164')->inRandomOrder()->first() : null;
        $router->incoming([
            'CallSid' => 'CAfake'.Str::lower(Str::random(26)),
            'From' => $customer->phone_e164 ?? '+1737555'.random_int(1100, 1999),
            'To' => $line->number,
        ]);

        return $this->state($agent->fresh());
    }

    private function state(User $agent): JsonResponse
    {
        $ringing = $agent->phone_status === AgentPresence::OFFLINE ? collect() : VoiceCall::withoutGlobalScopes()
            ->where('status', VoiceCall::RINGING)->where('started_at', '>=', now()->subMinutes(2))
            ->latest('started_at')->limit(50)->get()->filter(fn (VoiceCall $c) => $c->wasOfferedTo($agent))->take(3);
        $active = $this->activeCall($agent);

        return response()->json([
            'status' => $this->presence->status($agent),
            'label' => AgentPresence::LABELS[$this->presence->status($agent)] ?? '',
            'ringing' => $ringing->map(fn (VoiceCall $c) => $this->describe($c))->values(),
            'active' => $active ? $this->describe($active) : null,
        ]);
    }

    private function activeCall(User $agent): ?VoiceCall
    {
        return VoiceCall::withoutGlobalScopes()->where('answered_by_user_id', $agent->id)->where('status', VoiceCall::IN_PROGRESS)->latest('answered_at')->first();
    }

    /** @return array<string, mixed> */
    private function describe(VoiceCall $call): array
    {
        $organization = Organization::query()->find($call->organization_id);
        $customer = $call->customer_id ? Customer::query()->withoutGlobalScopes()->find($call->customer_id) : null;

        return [
            'id' => $call->ulid,
            'status' => $call->status,
            'business' => $organization?->name,
            'caller' => $customer?->fullName() ?: $call->fromDisplay(),
            'number' => $call->fromDisplay(),
            'known' => $customer !== null,
            'started_at' => $call->started_at->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'can_transfer' => (bool) $call->phoneNumber?->transfer_number,
            'workspace_url' => $organization ? route('agent.businesses.show', [$organization->ulid, 'call' => $call->ulid]) : null,
            'business_id' => $organization?->ulid,
        ];
    }

    private function authorizeCall(VoiceCall $call, User $agent): void
    {
        $organization = Organization::query()->find($call->organization_id);
        abort_unless($organization && ($call->wasOfferedTo($agent) || $call->answered_by_user_id === $agent->id)
            && $agent->hasPermissionIn('calls.create', $organization), 404);
    }
}
