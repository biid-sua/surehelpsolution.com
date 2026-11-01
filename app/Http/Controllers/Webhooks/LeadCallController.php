<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\Lead;
use App\Services\Telephony\Texting;
use App\Services\Telephony\TwiML;
use DOMElement;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The call that connects the owner to a new lead (D109), signed by Twilio like every voice webhook.
 * The owner's phone rings from the business's line; they hear who the lead is and press 1, and
 * the lead's phone rings showing the business's number.
 */
class LeadCallController extends Controller
{
    /** The owner picked up: who it is, and "press 1". */
    public function whisper(Lead $lead): Response
    {
        if ($lead->call_status !== 'calling_owner') {
            return $this->twiml(TwiML::make()->hangup());
        }
        $business = BusinessProfile::withoutGlobalScopes()->where('organization_id', $lead->organization_id)->value('display_name');
        $what = filled($lead->message) ? ' They said: '.Str::limit((string) $lead->message, 160).'.' : '';

        return $this->twiml(TwiML::make()
            ->gather(['input' => 'dtmf', 'numDigits' => 1, 'timeout' => 8, 'action' => route('webhooks.voice.lead-connect', $lead), 'method' => 'POST'],
                'New lead'.($business ? ' for '.$business : '').': '.($lead->name ?: 'someone').', from '.$lead->sourceLabel().'.'.$what.' Press 1 to call them now.')
            ->say('Okay. It\'s in your tasks. Goodbye.')
            ->hangup());
    }

    /** 1: ring the lead from the business's number. Anything else: leave it in the tasks. */
    public function connect(Request $request, Lead $lead, Texting $texting): Response
    {
        $line = $texting->lineFor($lead->organization);
        if ($request->input('Digits') !== '1' || ! $lead->phone || ! $line) {
            $lead->forceFill(['call_status' => 'declined'])->save();

            return $this->twiml(TwiML::make()->say('Okay. It\'s in your tasks. Goodbye.')->hangup());
        }
        $lead->forceFill(['call_status' => 'connecting'])->save();

        return $this->twiml(TwiML::make()->say('Calling them now.')->dial([
            'callerId' => $line->number,
            'timeout' => 30,
            'action' => route('webhooks.voice.lead-dialed', $lead),
        ], function (DOMElement $dial, TwiML $t) use ($lead): void {
            $t->into($dial, 'Number', (string) $lead->phone);
        }));
    }

    /** The lead's phone stopped ringing or the call ended. */
    public function dialed(Request $request, Lead $lead): Response
    {
        $answered = $request->input('DialCallStatus') === 'completed';
        $lead->forceFill([
            'call_status' => $answered ? 'connected' : 'lead_missed',
            'connected_at' => $answered ? ($lead->connected_at ?? now()) : $lead->connected_at,
            'first_response_at' => $lead->first_response_at ?? ($answered ? now() : null),
        ])->save();

        return $this->twiml($answered ? TwiML::make()->hangup()
            : TwiML::make()->say('They didn\'t answer. The task is still in your list. Goodbye.')->hangup());
    }

    /** The owner's leg ended: not answered at all means they missed it. */
    public function status(Request $request, Lead $lead): Response
    {
        if ($lead->call_status === 'calling_owner' && in_array($request->input('CallStatus'), ['no-answer', 'busy', 'failed', 'canceled', 'completed'], true)) {
            $lead->forceFill(['call_status' => 'owner_missed'])->save();
        }

        return response()->noContent();
    }

    private function twiml(TwiML $twiml): Response
    {
        return response($twiml->xml(), 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }
}
