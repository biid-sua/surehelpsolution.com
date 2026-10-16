<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Inbox\ReceiveMessage;
use App\Enums\InboxChannel;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Services\Inbox\TextedPictures;
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
    public function incoming(Request $request, ReceiveMessage $receive, Texting $texting, TextedPictures $pictures): Response
    {
        $line = PhoneNumber::withoutGlobalScopes()->active()->where('number', PhoneNumber::normalize((string) $request->input('To')))->first();
        $organization = $line ? Organization::query()->find($line->organization_id) : null;
        $from = PhoneNumber::normalize((string) $request->input('From'));
        if (! $organization || $from === null) {
            return $this->empty();
        }

        // Twilio retries: a text we already have isn't processed (or its pictures downloaded) twice.
        if (Message::withoutGlobalScopes()->where('organization_id', $organization->id)->where('direction', Message::IN)
            ->where('external_id', (string) $request->input('MessageSid'))->exists()) {
            return $this->empty();
        }

        $body = trim((string) $request->input('Body'));
        $keyword = Str::upper(preg_replace('/[^a-z]/i', '', $body) ?? '');
        if (in_array($keyword, Texting::STOP_WORDS, true)) {
            $texting->optOut($organization, $from);
        } elseif (in_array($keyword, Texting::START_WORDS, true)) {
            $texting->optIn($organization, $from);
        }

        $media = ['attachments' => [], 'skipped' => 0];
        $count = min(10, (int) $request->input('NumMedia', 0));
        if ($count > 0) {
            $media = $pictures->store($organization, array_values(array_filter(array_map(fn (int $i) => (string) $request->input("MediaUrl{$i}"), range(0, $count - 1)))));
            if ($media['skipped'] > 0) {
                $body = trim($body."\n\n".'('.$media['skipped'].' '.Str::plural('file', $media['skipped']).' sent by text couldn\'t be shown: only pictures up to 5 MB.)');
            }
        }

        $conversation = $texting->conversation($organization, $line, $from);
        $receive->handle($organization, InboxChannel::Sms, [
            'channel_key' => $line->number,
            'thread' => $from,
            'phone' => $from,
            'name' => $conversation->contact_name,
            'handle' => PhoneNumber::pretty($from),
            'external_id' => (string) $request->input('MessageSid'),
            'body' => $body !== '' ? $body : null,
            'attachments' => $media['attachments'],
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
