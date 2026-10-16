<?php

namespace App\Services\Inbox\Channels;

use App\Exceptions\SocialPostRejected;
use App\Exceptions\TelephonyUnavailable;
use App\Models\Conversation;
use App\Services\Telephony\Texting;

/** Replies by text from the business's SureHelp number (D61). */
class SmsSender implements ChannelSender
{
    public function __construct(private readonly Texting $texting) {}

    public function send(Conversation $conversation, string $text, bool $humanAgentTag): ?string
    {
        try {
            return $this->texting->deliver($conversation, $text);
        } catch (TelephonyUnavailable $e) {
            throw new SocialPostRejected($e->getMessage());
        }
    }
}
