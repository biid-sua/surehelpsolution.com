<?php

namespace App\Services\Inbox\Channels;

use App\Models\Conversation;

/**
 * Website chat: the visitor's widget fetches new messages itself, so "sending" is storing it.
 */
class WebChatSender implements ChannelSender
{
    public function send(Conversation $conversation, string $text, bool $humanAgentTag): ?string
    {
        return null;
    }
}
