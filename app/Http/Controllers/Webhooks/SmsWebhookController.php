<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Inbox\ReceiveMessage;
use App\Enums\InboxChannel;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Services\Telephony\Texting;
use App\Services\Telephony\TwiML;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Texts to a business's SureHelp number land in its Inbox (D61); STOP and START are honoured.
 * Signed by Twilio like the call webhooks.
 */
class SmsWebhookController extends Controller
{
    public function incoming(Request $request, ReceiveMessage $receive, Texting $texting): Response
    {
        $line = PhoneNumber::withoutGlobalScopes()->active()->where('number', PhoneNumber::normalize((string) $request->input('To')))->first();
        $organization = $line ? Organization::query()->find($line->organization_id) : null;
        $from = PhoneNumber::normalize((string) $request->input('From'));
        if (! $organization || $from === null) {
            return $this->empty();
        }

        $body = trim((string) $request->input('Body'));
        $keyword = Str::upper(preg_replace('/[^a-z]/i', '', $body) ?? '');
        if (in_array($keyword, Texting::STOP_WORDS, true)) {
            $texting->optOut($organization, $from);
        } elseif (in_array($keyword, Texting::START_WORDS, true)) {
            $texting->optIn($organization, $from);
        }

        $pictures = (int) $request->input('NumMedia', 0);
        if ($pictures > 0) {
            $body = trim($body."\n\n".'('.$pictures.' '.Str::plural('picture', $pictures).' sent by text; pictures aren\'t shown here yet.)');
        }

        $conversation = $texting->conversation($organization, $line, $from);
        $receive->handle($organization, InboxChannel::Sms, [
            'channel_key' => $line->number,
            'thread' => $from,
            'phone' => $from,
            'name' => $conversation->contact_name,
            'handle' => PhoneNumber::pretty($from),
            'external_id' => (string) $request->input('MessageSid'),
            'body' => $body,
        ]);

        return $this->empty(); // no automatic reply from here; Twilio answers STOP/HELP itself
    }

    /** Delivery reports for texts we sent. */
    public function status(Request $request): Response
    {
        $message = Message::withoutGlobalScopes()->where('direction', Message::OUT)->where('external_id', (string) $request->input('MessageSid'))->first();
        $status = (string) $request->input('MessageStatus');
        if ($message && in_array($status, ['failed', 'undelivered'], true)) {
            $code = (string) $request->input('ErrorCode');
            $message->forceFill(['status' => 'failed', 'error' => match ($code) {
                '21610' => 'This person texted STOP, so the text wasn\'t delivered.',
                '30003', '30005', '30006' => 'Their phone couldn\'t receive the text (switched off, wrong number, or a landline).',
                '30007', '30034' => 'The carrier blocked the text. This happens until the number\'s texting registration is approved.',
                default => 'The text couldn\'t be delivered'.($code !== '' ? " (carrier error {$code})" : '').'.',
            }])->save();
        }

        return response()->noContent();
    }

    private function empty(): Response
    {
        return response(TwiML::make()->xml(), 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }
}
