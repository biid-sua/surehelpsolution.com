<?php

namespace App\Services\Ai\Assistant;

use App\Actions\Inbox\SendReply;
use App\Exceptions\AiUnavailable;
use App\Models\AiAssistant;
use App\Models\AiRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Billing\Entitlements;
use App\Services\Billing\FeatureAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * The AI messaging assistant (spec §26A, D38): reads the conversation, uses tools, then replies
 * (auto) or drafts a reply for a person (suggest). Every guardrail lives here:
 * switched off, a person took over, Meta's window, rate and plan limits, step limit, hand-over.
 */
class ConversationAssistant
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly BusinessBrain $brain,
        private readonly AssistantTools $tools,
        private readonly SendReply $send,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * Answer the latest customer message, if the assistant should.
     */
    public function respond(Conversation $conversation, ?int $triggerMessageId = null): ?AiRun
    {
        $lock = Cache::lock('ai-conversation-'.$conversation->id, 180);
        if (! $lock->get()) {
            return null; // another reply is being written right now
        }

        try {
            return $this->run($conversation->fresh() ?? $conversation, $triggerMessageId);
        } finally {
            $lock->release();
        }
    }

    private function run(Conversation $conversation, ?int $triggerMessageId): ?AiRun
    {
        $organization = $conversation->organization;
        $assistant = AiAssistant::for($organization);
        $mode = $assistant->modeFor($conversation->channel);

        if ($mode === 'off' || ! $this->ai->isConfigured() || ! $conversation->isOpen()
            || ! app(FeatureAccess::class)->allows($organization, 'ai_assistant')) {   // not in the plan (D46)
            return null;
        }

        $last = $conversation->messages()->reorder()->where('is_note', false)->whereIn('status', ['received', 'sent'])->latest('id')->first();
        if (! $last || $last->direction !== Message::IN || ($triggerMessageId && $last->id !== $triggerMessageId)) {
            return null; // already answered, or a newer message will be answered by its own run
        }

        if ($mode === 'auto' && ($conversation->ai_paused || $conversation->needs_human)) {
            return $this->skip($conversation, $mode, 'A person is handling this conversation.');
        }
        if ($mode === 'auto' && ! $conversation->channel->replyWindow($conversation->last_inbound_at, false)['allowed']) {
            return $this->skip($conversation, $mode, 'Outside the channel\'s 24-hour window for automatic replies.');
        }
        $recent = AiRun::query()->where('conversation_id', $conversation->id)->whereIn('status', ['replied', 'drafted', 'handed_over'])
            ->where('created_at', '>=', now()->subHour())->count();
        if ($recent >= (int) config('ai.assistant.max_replies_per_hour', 20)) {
            return $this->skip($conversation, $mode, 'Too many AI replies in this conversation in the last hour.', handOver: $mode === 'auto');
        }
        $limit = $this->entitlements->limit($organization, 'ai_replies');
        if ($limit !== null && AiRun::query()->forOrganization($organization)->whereIn('status', ['replied', 'drafted', 'handed_over'])
            ->where('created_at', '>=', now()->startOfMonth())->count() >= $limit) {
            return $this->skip($conversation, $mode, "The plan's {$limit} AI replies this month are used up.", handOver: $mode === 'auto');
        }

        $canAct = $mode === 'auto';
        $run = AiRun::create([
            'organization_id' => $organization->id, 'conversation_id' => $conversation->id, 'mode' => $mode,
            'model' => $this->ai->model(), 'status' => 'failed',
        ]);
        $outcome = new ToolOutcome;
        $calls = [];
        $usage = ['input' => 0, 'output' => 0, 'cache_read' => 0];
        $steps = 0;
        $text = '';
        $stopReason = 'end_turn';

        try {
            $messages = $this->history($conversation);
            $instructions = $this->brain->instructions($organization, $assistant, $canAct);
            $context = $this->brain->context($conversation, $canAct);
            $definitions = $this->tools->definitions($assistant, $canAct);
            $maxSteps = (int) config('ai.assistant.max_steps', 6);

            while (true) {
                $steps++;
                $response = $this->ai->complete(new AiRequest($instructions, $context, $messages, $definitions));
                foreach ($usage as $key => $value) {
                    $usage[$key] = $value + $response->usage[$key];
                }
                $text = $response->text;
                $stopReason = $response->stopReason;

                if (! $response->wantsTools()) {
                    break;
                }
                if ($steps >= $maxSteps || $outcome->handedOver) {
                    $stopReason = 'too_many_steps';
                    break;
                }

                $messages[] = ['role' => 'assistant', 'content' => $response->providerContent];
                $results = [];
                foreach ($response->toolCalls as $call) {
                    $allowed = $canAct || ! in_array($call['name'], AssistantTools::ACTIONS, true);
                    $result = $allowed
                        ? $this->tools->run($call['name'], (array) $call['input'], $conversation, $assistant, $outcome)
                        : ['ok' => false, 'content' => 'Not available while drafting.', 'summary' => 'refused (draft mode)'];
                    $calls[] = ['name' => $call['name'], 'input' => (array) $call['input'], 'ok' => $result['ok'], 'summary' => $result['summary']];
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $call['id'], 'content' => $result['content'], 'is_error' => ! $result['ok']];
                }
                // All results of one turn go back together, in one message.
                $messages[] = ['role' => 'user', 'content' => $results];
            }
        } catch (AiUnavailable $e) {
            report($e);

            return $this->finish($run, $calls, $usage, $steps, 'failed', 'The AI service is unavailable: the team was told.', $conversation, handOver: true);
        }

        // Refused, cut off or going round in circles: a person takes it from here.
        if (in_array($stopReason, ['refusal', 'max_tokens', 'too_many_steps'], true) && ! $outcome->handedOver && $canAct) {
            $conversation->forceFill(['needs_human' => true, 'ai_paused' => true])->save();
            $outcome->handedOver = true;
            $text = $assistant->handoverMessage();
        }
        if ($outcome->handedOver && trim($text) === '') {
            $text = $assistant->handoverMessage();
        }
        if (trim($text) === '') {
            return $this->finish($run, $calls, $usage, $steps, 'skipped', 'No reply was needed.', $conversation);
        }

        if ($mode === 'suggest') {
            $conversation->messages()
                ->where('author_type', 'ai')->where('status', 'draft')->update(['status' => 'discarded']); // only the latest suggestion stays
            $conversation->messages()->create([
                'organization_id' => $organization->id, 'direction' => Message::OUT, 'author_type' => 'ai',
                'body' => $text, 'status' => 'draft', 'ai_run_id' => $run->id,
            ]);

            return $this->finish($run, $calls, $usage, $steps, 'drafted', null, $conversation);
        }

        try {
            $this->send->handle($conversation, $text, null, run: $run);
        } catch (ValidationException $e) {
            return $this->finish($run, $calls, $usage, $steps, 'failed', implode(' ', array_merge(...array_values($e->errors()))), $conversation, handOver: true);
        }

        return $this->finish($run, $calls, $usage, $steps, $outcome->handedOver ? 'handed_over' : 'replied', $outcome->handedOver ? 'Handed to the team.' : null, $conversation);
    }

    /**
     * The conversation as the model sees it: the customer is "user", the business (people and AI) is "assistant".
     * Notes, drafts and failed sends are left out. It must start with the customer.
     *
     * @return list<array{role: string, content: string}>
     */
    private function history(Conversation $conversation): array
    {
        $messages = $conversation->messages()->reorder()->where('is_note', false)->whereIn('status', ['received', 'sent'])
            ->latest('id')->limit((int) config('ai.assistant.history', 40))->get()->reverse()->values();

        $out = [];
        foreach ($messages as $m) {
            $text = trim((string) $m->body);
            if ($m->attachments) {
                $text = trim($text.' [sent '.count($m->attachments).' attachment(s)]');
            }
            if ($text === '') {
                continue;
            }
            $role = $m->direction === Message::IN ? 'user' : 'assistant';
            if ($out === [] && $role === 'assistant') {
                continue;
            }
            $out[] = ['role' => $role, 'content' => $role === 'assistant' && $m->author_type === 'user' ? '[team member] '.$text : $text];
        }

        return $out;
    }

    private function skip(Conversation $conversation, string $mode, string $reason, bool $handOver = false): AiRun
    {
        if ($handOver) {
            $conversation->forceFill(['needs_human' => true])->save();
        }

        return AiRun::create([
            'organization_id' => $conversation->organization_id, 'conversation_id' => $conversation->id, 'mode' => $mode,
            'model' => $this->ai->model(), 'status' => 'skipped', 'reason' => $reason,
        ]);
    }

    /**
     * @param  list<array{name: string, input: array<string, mixed>, ok: bool, summary: string}>  $calls
     * @param  array{input: int, output: int, cache_read: int}  $usage
     */
    private function finish(AiRun $run, array $calls, array $usage, int $steps, string $status, ?string $reason, Conversation $conversation, bool $handOver = false): AiRun
    {
        if ($handOver) {
            $conversation->forceFill(['needs_human' => true])->save();
        }

        $run->forceFill([
            'status' => $status,
            'reason' => $reason ? mb_substr($reason, 0, 500) : null,
            'steps' => $steps,
            'input_tokens' => $usage['input'],
            'output_tokens' => $usage['output'],
            'cache_read_tokens' => $usage['cache_read'],
            'tool_calls' => $calls ?: null,
        ])->save();

        return $run;
    }
}
