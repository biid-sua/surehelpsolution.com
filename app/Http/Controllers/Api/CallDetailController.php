<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\Calls\CallOutcomes;
use App\Services\Telephony\TelephonyProvider;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * One call for the business's app (API-6): what the agent logged and, for calls on a SureHelp line, the
 * phone call itself (D60). The recording, AI summary and transcript need `calls.recording.view`, as on
 * the web; the recording comes as a short-lived signed link an audio player can open without headers.
 */
class CallDetailController extends Controller
{
    public function show(Request $request, CurrentOrganization $current, CallOutcomes $outcomes, string $callId): JsonResponse
    {
        $organization = $current->get();
        abort_if($organization === null, 403);
        $call = CallLog::query()->forOrganization($organization)->where('call_id', $callId)->with(['customer', 'voiceCall'])->firstOrFail();
        abort_unless($request->user()->can('view', $call), 404);

        $user = $request->user();
        $voice = $call->voiceCall;
        $canHear = $user->hasPermissionIn('calls.recording.view', $organization);

        return ApiResponse::success(['call' => [
            'id' => $call->call_id,
            'created_at' => $call->created_at?->toIso8601String(),
            'caller' => ['name' => $call->caller_name, 'phone' => $call->caller_phone, 'email' => $call->caller_email],
            'customer' => $call->customer ? ['id' => $call->customer->ulid, 'name' => $call->customer->fullName()] : null,
            'reason' => $call->reason_for_call,
            'outcome' => $call->call_outcome,
            'outcome_label' => $outcomes->label($organization, $call->call_outcome) ?? $call->call_outcome,
            'status' => $call->status,
            'agent_name' => $call->agent_name,
            'notes' => $call->notes,
            'service' => $call->service_request ? [
                'date' => $call->service_date?->toDateString(),
                'window' => $call->service_window,
                'location' => $call->service_location,
            ] : null,
            'tasks' => $user->can('tasks.view', $organization) ? $call->tasks()->latest('id')->get(['ulid', 'title', 'status', 'due_at'])
                ->map(fn (Task $t) => ['id' => $t->ulid, 'title' => $t->title, 'status' => $t->status->value, 'due_at' => $t->due_at?->toIso8601String()])->values() : null,
            'phone_call' => $voice ? [
                'direction' => $voice->direction,
                'status' => $voice->status,
                'status_label' => $voice->statusLabel(),
                'other_party' => $voice->otherParty(),
                'after_hours' => (bool) $voice->after_hours,
                'started_at' => $voice->started_at->toIso8601String(),
                'answered_at' => $voice->answered_at?->toIso8601String(),
                'ended_at' => $voice->ended_at?->toIso8601String(),
                'wait_seconds' => $voice->wait_seconds,
                'duration_seconds' => $voice->duration_seconds,
                'transferred_to' => $voice->transferred_to,
                'recording' => $canHear && $voice->hasRecording() ? [
                    'url' => URL::temporarySignedRoute('api.recordings.play', now()->addMinutes((int) config('mobile.recording_link_minutes', 15)), ['voiceCall' => $voice->ulid, 'user' => $user->id]),
                    'seconds' => $voice->recording_seconds,
                    'expires_in' => (int) config('mobile.recording_link_minutes', 15) * 60,
                ] : null,
                'summary' => $canHear ? $voice->summary : null,
                'transcript' => $canHear ? ($voice->transcript ?? []) : null,
                'can_hear' => $canHear,
            ] : null,
        ]]);
    }

    /** The recording behind a signed link; still checks that the person may hear it, and audits the play. */
    public function recording(Request $request, VoiceCall $voiceCall, TelephonyProvider $provider, Audit $audit): Response
    {
        $user = User::query()->find((int) $request->query('user'));
        $organization = $voiceCall->organization_id ? Organization::query()->find($voiceCall->organization_id) : null;
        abort_unless($user && $user->is_active && $organization && $voiceCall->recording_sid && $user->hasPermissionIn('calls.recording.view', $organization), 404);

        $audio = $provider->recordingAudio($voiceCall->recording_sid);
        abort_if($audio === null, 404);
        if (! $request->headers->has('Range') || str_starts_with((string) $request->header('Range'), 'bytes=0-')) {
            $audit->record('call.recording_played', $voiceCall, new: ['channel' => 'app'], organization: $organization, actor: $user, label: $voiceCall->fromDisplay());
        }

        return response($audio, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => (string) strlen($audio),
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline; filename="call-'.$voiceCall->ulid.'.mp3"',
        ]);
    }
}
