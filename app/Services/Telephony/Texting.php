<?php

namespace App\Services\Telephony;

use App\Actions\Customers\MatchOrCreateCustomer;
use App\Enums\InboxChannel;
use App\Exceptions\TelephonyUnavailable;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\SmsOptOut;
use App\Support\Audit\Audit;

/**
 * Texts from a business's SureHelp number (D61). Every text, by a person, the AI or an automation,
 * lives in an Inbox conversation, so replies continue there. Numbers that texted STOP are never
 * texted, and US texting stays off until the business's carrier registration (A2P 10DLC) is approved.
 */
class Texting
{
    /** Carrier opt-out and opt-in words (CTIA). Twilio also answers these itself. */
    public const STOP_WORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'OPTOUT', 'REVOKE'];

    public const START_WORDS = ['START', 'UNSTOP', 'YES', 'OPTIN'];

    public function __construct(
        private readonly TelephonyProvider $provider,
        private readonly Audit $audit,
    ) {}

    /** Texting is switched on (always with the demo phone; with Twilio once TELEPHONY_SMS is set). */
    public function enabled(): bool
    {
        return $this->provider->name() === 'fake' || (bool) config('telephony.sms');
    }

    public function lineFor(Organization $organization): ?PhoneNumber
    {
        return PhoneNumber::query()->forOrganization($organization)->active()->orderBy('id')->first();
    }

    public function optedOut(int $organizationId, string $phone): bool
    {
        return SmsOptOut::withoutGlobalScopes()->where('organization_id', $organizationId)->where('phone', $phone)->exists();
    }

    /** STOP: never text this number again (and the customer's texting consent is withdrawn). */
    public function optOut(Organization $organization, string $phone): void
    {
        SmsOptOut::withoutGlobalScopes()->firstOrCreate(['organization_id' => $organization->id, 'phone' => $phone], ['opted_out_at' => now()]);
        Customer::query()->forOrganization($organization)->where('phone_e164', $phone)->update(['sms_consent' => false, 'sms_consent_at' => now()]);
        $this->audit->record('sms.opted_out', null, new: ['phone' => $phone], organization: $organization, actor: null, label: PhoneNumber::pretty($phone));
    }

    /** START: texts may resume. Consent for marketing is not assumed; only replies and service texts. */
    public function optIn(Organization $organization, string $phone): void
    {
        SmsOptOut::withoutGlobalScopes()->where('organization_id', $organization->id)->where('phone', $phone)->delete();
        $this->audit->record('sms.opted_in', null, new: ['phone' => $phone], organization: $organization, actor: null, label: PhoneNumber::pretty($phone));
    }

    /**
     * Deliver one text in a conversation.
     *
     * @throws TelephonyUnavailable with a message safe to show
     */
    public function deliver(Conversation $conversation, string $body): string
    {
        if (! $this->enabled()) {
            throw new TelephonyUnavailable('Texting isn\'t switched on yet: your number\'s carrier registration is still being approved.');
        }
        $to = (string) $conversation->external_thread_id;
        if ($this->optedOut($conversation->organization_id, $to)) {
            throw new TelephonyUnavailable('This person texted STOP, so they can\'t be texted. They can text START to your number to allow texts again.');
        }
        $line = PhoneNumber::withoutGlobalScopes()->active()->where('organization_id', $conversation->organization_id)->where('number', $conversation->channel_key)->first();
        if (! $line) {
            throw new TelephonyUnavailable('The number this conversation used is no longer active.');
        }

        return $this->provider->sendSms($line->number, $to, $body, route('webhooks.sms.status'));
    }

    /**
     * An automatic text from the business (missed-call text-back, reminders). Null when it can't or
     * mustn't go out: texting off, no line, not a US/Canadian mobile-style number, or opted out.
     */
    public function sendAutomatic(Organization $organization, string $to, string $body, string $reason): ?Message
    {
        $to = PhoneNumber::normalize($to);
        $line = $this->lineFor($organization);
        if (! $this->enabled() || ! $line || $to === null || ! str_starts_with($to, '+1') || $to === $line->number || $this->optedOut($organization->id, $to)) {
            return null;
        }

        $conversation = $this->conversation($organization, $line, $to);
        $message = $conversation->messages()->create([
            'organization_id' => $organization->id, 'direction' => Message::OUT, 'author_type' => 'system',
            'body' => mb_substr($body, 0, InboxChannel::Sms->maxLength()), 'status' => 'pending',
        ]);
        try {
            $message->forceFill(['status' => 'sent', 'external_id' => $this->deliver($conversation, $message->body), 'sent_at' => now()])->save();
        } catch (TelephonyUnavailable $e) {
            $message->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
        }
        $conversation->forceFill(['last_message_at' => now()])->save();
        $this->audit->record('sms.sent_automatically', $conversation, new: ['reason' => $reason, 'status' => $message->status], organization: $organization, actor: null, label: PhoneNumber::pretty($to));

        return $message;
    }

    /** The texting conversation between a line and a number, created (and linked to its customer) when new. */
    public function conversation(Organization $organization, PhoneNumber $line, string $phone): Conversation
    {
        $conversation = Conversation::query()->forOrganization($organization)->where('channel', InboxChannel::Sms->value)
            ->where('channel_key', $line->number)->where('external_thread_id', $phone)->first();
        if ($conversation) {
            return $conversation;
        }
        $customer = app(MatchOrCreateCustomer::class)->lookup($organization, $phone, null)['customer'] ?? null;

        return Conversation::create([
            'organization_id' => $organization->id, 'channel' => InboxChannel::Sms, 'channel_key' => $line->number, 'external_thread_id' => $phone,
            'customer_id' => $customer?->id, 'contact_name' => $customer?->fullName(), 'contact_handle' => PhoneNumber::pretty($phone),
        ]);
    }
}
