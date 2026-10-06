<?php

namespace App\Actions\Inbox;

use App\Models\AiFeedback;
use App\Models\AiGuideline;
use App\Models\Message;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A team member rates an AI reply (D39). "What should it have said?" becomes a draft guideline;
 * nothing changes the assistant until someone with ai.manage approves it.
 */
class RateAiReply
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Message $message, User $user, string $rating, ?string $correction = null): AiFeedback
    {
        if (! $message->isFromAi()) {
            throw ValidationException::withMessages(['rating' => ['Only AI replies can be rated.']]);
        }
        if (! in_array($rating, ['helpful', 'unhelpful'], true)) {
            throw ValidationException::withMessages(['rating' => ['Choose helpful or not helpful.']]);
        }

        $correction = filled($correction) ? Str::limit(trim((string) $correction), 1000, '') : null;

        $feedback = AiFeedback::updateOrCreate(
            ['message_id' => $message->id, 'user_id' => $user->id],
            ['organization_id' => $message->organization_id, 'rating' => $rating, 'correction' => $correction],
        );

        if ($correction && ! $feedback->ai_guideline_id) {
            $guideline = AiGuideline::create([
                'organization_id' => $message->organization_id,
                'text' => $correction,
                'source_message_id' => $message->id,
                'created_by_user_id' => $user->id,
            ]);
            $feedback->forceFill(['ai_guideline_id' => $guideline->id])->save();
        }

        $this->audit->record('ai.feedback', $message, new: ['rating' => $rating, 'correction' => $correction !== null], organization: $message->conversation?->organization, actor: $user, label: 'AI reply');

        return $feedback;
    }
}
