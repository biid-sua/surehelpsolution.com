<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\Ai\Assistant\ConversationAssistant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Lets the assistant answer a new message. Dispatched after the response is sent, so the customer
 * (or Meta's webhook) never waits for the AI, even on hosting without a queue worker.
 */
class RespondToConversation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly int $conversationId, public readonly ?int $triggerMessageId = null) {}

    public function handle(ConversationAssistant $assistant): void
    {
        $conversation = Conversation::withoutGlobalScopes()->find($this->conversationId);

        if ($conversation) {
            $assistant->respond($conversation, $this->triggerMessageId);
        }
    }
}
