<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\Telephony\AgentPresence;
use App\Services\Telephony\CallRouter;
use App\Services\Telephony\VoiceCalls;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Twilio calls these as a call goes (D60). Every request is signed (VerifyTelephonyWebhook);
 * call instructions go back as TwiML, status updates get an empty 204.
 */
class VoiceWebhookController extends Controller
{
    public function __construct(
        private readonly CallRouter $router,
        private readonly VoiceCalls $calls,
    ) {}

    /** A call reached one of our numbers. */
    public function incoming(Request $request): Response
    {
        return $this->twiml($this->router->incoming($request->all()));
    }

    /** An agent's browser places a call (the TwiML App's voice URL, D61). */
    public function outgoing(Request $request): Response
    {
        return $this->twiml($this->router->outgoing($request->all()));
    }

    /** The person an agent called answered or hung up. */
    public function callee(Request $request, VoiceCall $voiceCall): Response
    {
        if ($voiceCall->isOutbound() && $request->input('CallStatus') === 'in-progress') {
            $this->calls->calleeAnswered($voiceCall);
        }

        return response()->noContent();
    }

    /** Status of the whole call (set on each number): we only need to know when it's over. */
    public function status(Request $request): Response
    {
        $call = VoiceCall::withoutGlobalScopes()->where('provider_call_sid', (string) $request->input('CallSid'))->first();
        if ($call && in_array($request->input('CallStatus'), ['completed', 'busy', 'no-answer', 'failed', 'canceled'], true)) {
            if ($request->input('CallStatus') === 'failed' && $call->status === VoiceCall::RINGING) {
                $call->forceFill(['status' => VoiceCall::FAILED])->save();
            }
            $this->calls->ended($call);
        }

        return response()->noContent();
    }

    /** An agent's browser answered or left the call. */
    public function agentLeg(Request $request, VoiceCall $voiceCall): Response
    {
        $agent = User::query()->find(AgentPresence::userIdFrom((string) $request->input('To')));
        if ($agent && $voiceCall->wasOfferedTo($agent)) {
            match ((string) $request->input('CallStatus')) {
                'in-progress' => $this->calls->answer($voiceCall, $agent),
                'completed' => $this->calls->agentLeft($voiceCall->refresh(), $agent),
                default => null,
            };
        }

        return response()->noContent();
    }

    public function dialFinished(Request $request, VoiceCall $voiceCall): Response
    {
        return $this->twiml($this->router->dialFinished($voiceCall, $request->all()));
    }

    public function transfer(VoiceCall $voiceCall): Response
    {
        return $this->twiml($this->router->transfer($voiceCall));
    }

    public function ownerFinished(Request $request, VoiceCall $voiceCall): Response
    {
        return $this->twiml($this->router->ownerFinished($voiceCall, $request->all()));
    }

    public function voicemailSaved(): Response
    {
        return $this->twiml($this->router->voicemailSaved());
    }

    public function recording(Request $request, VoiceCall $voiceCall): Response
    {
        if ($request->input('RecordingStatus', 'completed') === 'completed' && $request->filled('RecordingSid')) {
            $this->calls->recordingReady($voiceCall, (string) $request->input('RecordingSid'),
                $request->filled('RecordingDuration') ? (int) $request->input('RecordingDuration') : null, $request->boolean('voicemail'));
        }

        return response()->noContent();
    }

    /** Live transcription: one finished sentence at a time, then "stopped". */
    public function transcription(Request $request, VoiceCall $voiceCall): Response
    {
        $event = (string) $request->input('TranscriptionEvent');
        if ($event === 'transcription-content' && $request->input('Final', 'true') !== 'false') {
            $data = json_decode((string) $request->input('TranscriptionData'), true);
            $text = is_array($data) ? (string) ($data['transcript'] ?? '') : (string) $request->input('TranscriptionData');
            $this->calls->heard($voiceCall, str_starts_with((string) $request->input('Track'), 'outbound') ? 'agent' : 'caller', $text);
        } elseif ($event === 'transcription-stopped') {
            $this->calls->transcriptFinished($voiceCall);
        }

        return response()->noContent();
    }

    private function twiml(string $xml): Response
    {
        return response($xml, 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }
}
