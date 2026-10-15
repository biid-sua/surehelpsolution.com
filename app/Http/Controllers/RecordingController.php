<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\VoiceCall;
use App\Services\Telephony\TelephonyProvider;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plays a call recording (D60). Recordings stay with the telephony vendor and pass through here,
 * so only people with `calls.recording.view` in the call's business can hear them; each play is audited.
 */
class RecordingController extends Controller
{
    public function __invoke(Request $request, VoiceCall $voiceCall, TelephonyProvider $provider, Audit $audit): Response
    {
        $organization = Organization::query()->find($voiceCall->organization_id);
        abort_unless($organization && $voiceCall->recording_sid && $request->user()->hasPermissionIn('calls.recording.view', $organization), 404);

        $audio = $provider->recordingAudio($voiceCall->recording_sid);
        abort_if($audio === null, 404);

        if (! $request->headers->has('Range') || str_starts_with((string) $request->header('Range'), 'bytes=0-')) {
            $audit->record('call.recording_played', $voiceCall, organization: $organization, label: $voiceCall->fromDisplay());
        }

        return response($audio, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => (string) strlen($audio),
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline; filename="call-'.$voiceCall->ulid.'.mp3"',
        ]);
    }
}
