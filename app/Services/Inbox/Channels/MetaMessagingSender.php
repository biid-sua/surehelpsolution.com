<?php

namespace App\Services\Inbox\Channels;

use App\Exceptions\SocialPostRejected;
use App\Models\Conversation;
use App\Services\Social\Connectors\MetaGraph;
use App\Services\Social\SocialManager;

/**
 * Messenger and Instagram direct messages through Meta's Send API, with the Page token the business
 * granted when it connected Facebook & Instagram. Outside 24 hours only a person may reply, with the
 * HUMAN_AGENT tag (D37).
 */
class MetaMessagingSender implements ChannelSender
{
    public function __construct(
        private readonly MetaGraph $graph,
        private readonly SocialManager $social,
    ) {}

    public function send(Conversation $conversation, string $text, bool $humanAgentTag): ?string
    {
        $account = $conversation->socialAccount;
        if (! $account) {
            throw new SocialPostRejected('This page or account is no longer connected. Reconnect it in Inbox › Channels.');
        }

        $data = [
            'recipient' => json_encode(['id' => $conversation->external_thread_id]),
            'message' => json_encode(['text' => $text]),
            'messaging_type' => $humanAgentTag ? 'MESSAGE_TAG' : 'RESPONSE',
        ];
        if ($humanAgentTag) {
            $data['tag'] = 'HUMAN_AGENT';
        }

        $json = $this->graph->post($account->external_id.'/messages', $this->social->accessToken($account), $data);

        return isset($json['message_id']) ? (string) $json['message_id'] : null;
    }
}
