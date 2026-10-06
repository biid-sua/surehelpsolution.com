<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Inbox\ReceiveMessage;
use App\Enums\InboxChannel;
use App\Enums\SocialNetwork;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SocialAccount;
use App\Services\Social\Connectors\MetaGraph;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Messenger and Instagram messages from Meta (D37). Every delivery is checked against Meta's
 * signature (X-Hub-Signature-256, HMAC with the app secret) before anything is read.
 */
class MetaWebhookController extends Controller
{
    /** Meta's one-time check when the webhook URL is registered. */
    public function verify(Request $request): Response
    {
        $token = (string) config('social.connectors.meta.webhook_verify_token');

        if ($token !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
        }

        return response('', 403);
    }

    public function receive(Request $request, ReceiveMessage $receive, MetaGraph $graph): Response
    {
        $secret = (string) config('social.connectors.meta.client_secret');
        $expected = 'sha256='.hash_hmac('sha256', (string) $request->getContent(), $secret);
        if ($secret === '' || ! hash_equals($expected, (string) $request->header('X-Hub-Signature-256'))) {
            return response('', 403);
        }

        $object = (string) $request->input('object');
        $channel = match ($object) {
            'page' => InboxChannel::Facebook,
            'instagram' => InboxChannel::Instagram,
            default => null,
        };
        if (! $channel) {
            return response('EVENT_RECEIVED', 200);
        }
        $network = $channel === InboxChannel::Facebook ? SocialNetwork::Facebook : SocialNetwork::Instagram;

        foreach ((array) $request->input('entry', []) as $entry) {
            $account = SocialAccount::withoutGlobalScopes()->where('network', $network->value)
                ->where('external_id', (string) ($entry['id'] ?? ''))->where('messaging_enabled', true)->with('organization')->first();
            if (! $account || ! $account->organization) {
                continue;
            }

            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                $message = $event['message'] ?? null;
                if (! is_array($message) || empty($message['mid'])) {
                    continue; // reads, deliveries, reactions
                }

                try {
                    if (! empty($message['is_echo'])) {
                        $this->echo($account, $channel, $event);

                        continue;
                    }

                    $sender = (string) ($event['sender']['id'] ?? '');
                    $receive->handle($account->organization, $channel, [
                        'channel_key' => $account->external_id,
                        'thread' => $sender,
                        'body' => $message['text'] ?? null,
                        'external_id' => (string) $message['mid'],
                        'attachments' => collect($message['attachments'] ?? [])->map(fn ($a) => [
                            'type' => (string) ($a['type'] ?? 'file'),
                            'url' => (string) ($a['payload']['url'] ?? ''),
                        ])->filter(fn ($a) => $a['url'] !== '')->values()->all(),
                        'name' => $this->profileName($graph, $account, $channel, $sender),
                        'social_account_id' => $account->id,
                        'at' => isset($event['timestamp']) ? CarbonImmutable::createFromTimestampMs((int) $event['timestamp']) : null,
                    ]);
                } catch (\Throwable $e) {
                    report($e); // one bad event never blocks the rest; Meta would retry the whole batch
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * Something the business sent from Facebook's or Instagram's own inbox: show it in ours and let the AI step back.
     * Our own replies echo back too; those are already stored.
     *
     * @param  array<string, mixed>  $event
     */
    private function echo(SocialAccount $account, InboxChannel $channel, array $event): void
    {
        $mid = (string) $event['message']['mid'];
        $conversation = Conversation::withoutGlobalScopes()->where('organization_id', $account->organization_id)
            ->where('channel', $channel->value)->where('channel_key', $account->external_id)
            ->where('external_thread_id', (string) ($event['recipient']['id'] ?? ''))->first();

        if (! $conversation || Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->where('external_id', $mid)->exists()) {
            return;
        }

        // Our own reply can echo back before we've stored its id: same text, sent from here moments ago.
        $ours = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->where('direction', Message::OUT)
            ->whereNull('external_id')->whereIn('status', ['pending', 'sent'])->where('body', (string) ($event['message']['text'] ?? ''))
            ->where('created_at', '>=', now()->subMinutes(2))->latest('id')->first();
        if ($ours) {
            $ours->forceFill(['external_id' => $mid])->save();

            return;
        }

        $conversation->messages()->create([
            'organization_id' => $conversation->organization_id,
            'direction' => Message::OUT,
            'author_type' => 'user',
            'body' => $event['message']['text'] ?? '(sent from '.$channel->label().')',
            'status' => 'sent',
            'external_id' => $mid,
            'sent_at' => now(),
        ]);
        $conversation->forceFill(['ai_paused' => true, 'needs_human' => false, 'last_message_at' => now()])->save();
    }

    /**
     * The customer's name, the first time they write (best effort; Meta may not share it).
     */
    private function profileName(MetaGraph $graph, SocialAccount $account, InboxChannel $channel, string $sender): ?string
    {
        $known = Conversation::withoutGlobalScopes()->where('organization_id', $account->organization_id)->where('channel', $channel->value)
            ->where('channel_key', $account->external_id)->where('external_thread_id', $sender)->exists();
        if ($known || $sender === '') {
            return null;
        }

        try {
            $profile = $graph->get($sender, $account->access_token, ['fields' => $channel === InboxChannel::Instagram ? 'name,username' : 'name']);

            return $profile['name'] ?? (isset($profile['username']) ? '@'.$profile['username'] : null);
        } catch (\Throwable) {
            return null;
        }
    }
}
