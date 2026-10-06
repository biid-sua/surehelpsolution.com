<?php

namespace App\Services\Inbox\Channels;

use App\Exceptions\SocialAuthorizationLost;
use App\Exceptions\SocialPostRejected;
use App\Models\Conversation;

/**
 * Delivers one reply on the channel the customer used (D37).
 */
interface ChannelSender
{
    /**
     * @return string|null the channel's id for the sent message
     *
     * @throws SocialAuthorizationLost when the page must be reconnected
     * @throws SocialPostRejected when the channel refused the message (e.g. outside its reply window)
     */
    public function send(Conversation $conversation, string $text, bool $humanAgentTag): ?string;
}
