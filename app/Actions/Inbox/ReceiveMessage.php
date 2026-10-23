<?php

namespace App\Actions\Inbox;

use App\Actions\Customers\MatchOrCreateCustomer;
use App\Actions\Customers\RecordTimelineEvent;
use App\Actions\Notifications\NotifyOrganization;
use App\Enums\InboxChannel;
use App\Enums\TimelineEventType;
use App\Jobs\RespondToConversation;
use App\Models\AiAssistant;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Survey;
use App\Notifications\InboxMessageReceived;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Automation\AutomationEngine;
use App\Services\Business\Branding;
use App\Services\Surveys\SurveyDesk;
use App\Services\Telephony\Texting;
use App\Support\CustomerLocale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A customer wrote to the business on any channel (spec §26). Idempotent per channel message id,
 * so a webhook delivered twice is stored once. Then either the AI assistant answers (§26A) or
 * the team is told.
 */
class ReceiveMessage
{
    public function __construct(
        private readonly MatchOrCreateCustomer $customers,
        private readonly RecordTimelineEvent $timeline,
        private readonly NotifyOrganization $notify,
        private readonly AiProvider $ai,
    ) {}

    /**
     * @param  array{channel_key: string, thread: string, body: ?string, external_id?: ?string, attachments?: list<array{type: string, url: string, name?: ?string}>, name?: ?string, handle?: ?string, email?: ?string, phone?: ?string, social_account_id?: ?int, at?: ?\DateTimeInterface}  $data
     */
    public function handle(Organization $organization, InboxChannel $channel, array $data): ?Message
    {
        $body = filled($data['body'] ?? null) ? Str::limit(trim((string) $data['body']), 5000, '') : null;
        $attachments = $data['attachments'] ?? [];
        if ($body === null && $attachments === []) {
            return null;
        }

        [$message, $conversation, $isNew] = DB::transaction(function () use ($organization, $channel, $data, $body, $attachments) {
            $conversation = Conversation::query()->forOrganization($organization)->where('channel', $channel->value)
                ->where('channel_key', $data['channel_key'])->where('external_thread_id', $data['thread'])->lockForUpdate()->first();
            $isNew = $conversation === null;

            $conversation ??= Conversation::create([
                'organization_id' => $organization->id,
                'channel' => $channel,
                'channel_key' => $data['channel_key'],
                'external_thread_id' => $data['thread'],
                'social_account_id' => $data['social_account_id'] ?? null,
                'contact_name' => $data['name'] ?? null,
                'contact_handle' => $data['handle'] ?? $data['email'] ?? $data['phone'] ?? null,
            ]);

            if (filled($data['external_id'] ?? null) && $conversation->messages()->where('direction', Message::IN)->where('external_id', $data['external_id'])->exists()) {
                return [null, $conversation, false];
            }

            $at = $data['at'] ?? now();
            $message = $conversation->messages()->create([
                'organization_id' => $organization->id,
                'direction' => Message::IN,
                'author_type' => 'customer',
                'body' => $body,
                'attachments' => $attachments ?: null,
                'status' => 'received',
                'external_id' => $data['external_id'] ?? null,
                'sent_at' => $at,
            ]);

            $conversation->forceFill([
                'status' => 'open',
                'closed_at' => null,
                'contact_name' => $conversation->contact_name ?: ($data['name'] ?? null),
                'unread_count' => $conversation->unread_count + 1,
                'last_message_at' => $at,
                'last_inbound_at' => $at,
            ])->save();

            return [$message, $conversation, $isNew];
        });

        if (! $message) {
            return null; // already had it
        }

        $this->linkCustomer($organization, $conversation, $data, $isNew);
        if ($channel === InboxChannel::Sms && $body !== null && $this->surveyReply($organization, $conversation, $body)) {
            return $message; // a rating for a survey (D84): recorded and thanked, nothing for the AI or the team
        }
        if ($isNew) {
            // Automations (D80): someone got in touch for the first time.
            app(AutomationEngine::class)->fire('conversation_started', $conversation->refresh());
        }
        $this->route($organization, $conversation, $message);

        return $message;
    }

    /** "4" in reply to a satisfaction survey text (D84). */
    private function surveyReply(Organization $organization, Conversation $conversation, string $body): bool
    {
        if (! $conversation->customer_id || preg_match('/^\s*([1-5])\s*(stars?|\/\s*5|out of 5)?\s*[.!]*\s*$/i', $body, $m) !== 1) {
            return false;
        }
        $survey = Survey::withoutGlobalScopes()->where('organization_id', $organization->id)->where('customer_id', $conversation->customer_id)
            ->where('channel', 'sms')->whereNull('answered_at')->where('expires_at', '>', now())->latest('sent_at')->first();
        if (! $survey || ! app(SurveyDesk::class)->answer($survey, (int) $m[1], 'text')) {
            return false;
        }
        $business = app(Branding::class)->for($organization)['name'];
        $l = CustomerLocale::forMessages($organization);   // D92
        $reply = $survey->score >= Survey::HAPPY
            ? ($survey->review_url ? __('Thank you! Would you share that in a quick Google review?', [], $l).' '.route('survey.review', $survey->token) : __('Thank you! We\'re glad we could help.', [], $l))
            : __('Thank you for telling us. We\'re sorry it wasn\'t better: someone from :business will be in touch.', ['business' => $business], $l);
        app(Texting::class)->sendAutomatic($organization, $conversation->external_thread_id, $reply, 'survey');
        $conversation->forceFill(['unread_count' => max(0, $conversation->unread_count - 1)])->save();

        return true;
    }

    /**
     * Website visitors who leave a name and email (or phone) become customers; their history follows them.
     *
     * @param  array<string, mixed>  $data
     */
    private function linkCustomer(Organization $organization, Conversation $conversation, array $data, bool $isNew): void
    {
        try {
            if (! $conversation->customer_id && (filled($data['email'] ?? null) || filled($data['phone'] ?? null))) {
                $match = $this->customers->handle($organization, [
                    'name' => $data['name'] ?? null, 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
                ], 'message');
                if ($match) {
                    $conversation->forceFill(['customer_id' => $match['customer']->id])->save();
                }
            }

            if ($isNew && $conversation->customer) {
                $this->timeline->handle($conversation->customer, TimelineEventType::MessageReceived,
                    'Message on '.$conversation->channel->label(), null, $conversation, ['conversation' => $conversation->ulid]);
            }
        } catch (\Throwable $e) {
            report($e); // never lose the message itself
        }
    }

    /**
     * The assistant answers when it's switched on for this channel; otherwise (or as well, in suggest
     * mode) the team hears about it, at most every 10 minutes per conversation.
     */
    private function route(Organization $organization, Conversation $conversation, Message $message): void
    {
        $mode = AiAssistant::for($organization)->modeFor($conversation->channel);
        $aiWillAnswer = $mode === 'auto' && $this->ai->isConfigured() && ! $conversation->ai_paused && ! $conversation->needs_human;

        if ($mode !== 'off' && $this->ai->isConfigured()) {
            RespondToConversation::dispatchAfterResponse($conversation->id, $message->id);
        }

        if ($aiWillAnswer) {
            return;
        }

        // A person has to answer (or approve the AI's suggestion): it shows under "Needs you".
        $conversation->forceFill(['needs_human' => true])->save();
        if (! $conversation->notified_at || $conversation->notified_at->lessThan(now()->subMinutes(10))) {
            $conversation->forceFill(['notified_at' => now()])->save();
            $this->notify->handle($organization, new InboxMessageReceived($conversation, $message), 'messages.view');
        }
    }
}
