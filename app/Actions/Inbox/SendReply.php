<?php

namespace App\Actions\Inbox;

use App\Enums\InboxChannel;
use App\Exceptions\SocialAuthorizationLost;
use App\Exceptions\SocialPostRejected;
use App\Models\AiRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Inbox\Channels\ChannelSender;
use App\Services\Inbox\Channels\MetaMessagingSender;
use App\Services\Inbox\Channels\WebChatSender;
use App\Services\Inbox\MessageSplitter;
use App\Services\Social\SocialManager;
use App\Support\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Sends a reply (or saves a team-only note) in a conversation, from a team member or the AI assistant.
 * Enforces the channel's reply window and splits replies that are too long for it (D37).
 */
class SendReply
{
    public function __construct(
        private readonly SocialManager $social,
        private readonly Audit $audit,
    ) {}

    /**
     * @return Message the last message stored
     *
     * @throws ValidationException when the reply can't be sent (empty, closed window, refused)
     */
    public function handle(Conversation $conversation, string $body, ?User $author, bool $note = false, ?AiRun $run = null): Message
    {
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages(['reply' => ['Write a message first.']]);
        }

        $byAi = $author === null;
        $base = [
            'organization_id' => $conversation->organization_id,
            'direction' => Message::OUT,
            'author_type' => $byAi ? 'ai' : 'user',
            'author_user_id' => $author?->id,
            'ai_run_id' => $run?->id,
        ];

        if ($note) {
            $message = $conversation->messages()->create($base + ['body' => mb_substr($body, 0, 5000), 'is_note' => true, 'status' => 'sent', 'sent_at' => now()]);
            $this->audit->record('inbox.note_added', $conversation, organization: $conversation->organization, actor: $author, label: $conversation->displayName());

            return $message;
        }

        $window = $conversation->channel->replyWindow($conversation->last_inbound_at, ! $byAi);
        if (! $window['allowed']) {
            throw ValidationException::withMessages(['reply' => [(string) $window['reason']]]);
        }

        $sender = $this->sender($conversation->channel);
        $message = null;

        foreach (MessageSplitter::split($body, $conversation->channel->maxLength()) as $part) {
            $message = $conversation->messages()->create($base + ['body' => $part, 'status' => 'pending']);

            try {
                $externalId = $sender->send($conversation, $part, $window['human_agent_tag']);
            } catch (SocialAuthorizationLost $e) {
                if ($conversation->socialAccount) {
                    $this->social->lost($conversation->socialAccount, $e->getMessage());
                }
                $this->failed($message, 'We lost access to this page. Reconnect Facebook & Instagram, then send again.');

                continue;
            } catch (SocialPostRejected $e) {
                $this->failed($message, $e->getMessage());

                continue;
            } catch (\Throwable $e) {
                report($e);
                $this->failed($message, 'The message couldn\'t be sent just now. Try again in a moment.');

                continue;
            }

            try {
                $message->forceFill(['status' => 'sent', 'external_id' => $externalId, 'sent_at' => now()])->save();
            } catch (UniqueConstraintViolationException) {
                // Meta's echo of this reply arrived first and already recorded its id: it was sent.
                $message->forceFill(['status' => 'sent', 'external_id' => null, 'sent_at' => now()])->save();
            }
        }

        $changes = ['last_message_at' => now()];
        if (! $byAi) {
            // A person answered: the assistant steps back in this conversation until the team hands it back.
            $changes += ['ai_paused' => true, 'needs_human' => false, 'unread_count' => 0];
            if (! $conversation->assigned_to_user_id) {
                $changes['assigned_to_user_id'] = $author->id;
            }
        }
        $conversation->forceFill($changes)->save();

        return $message ?? throw ValidationException::withMessages(['reply' => ['Write a message first.']]);
    }

    private function failed(Message $message, string $error): void
    {
        $message->forceFill(['status' => 'failed', 'error' => mb_substr($error, 0, 500)])->save();
    }

    private function sender(InboxChannel $channel): ChannelSender
    {
        return $channel === InboxChannel::WebChat ? app(WebChatSender::class) : app(MetaMessagingSender::class);
    }
}
