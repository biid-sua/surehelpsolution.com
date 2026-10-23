<?php

namespace App\Services\Sales;

use App\Exceptions\AiUnavailable;
use App\Models\SalesChat;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use Illuminate\Support\Facades\Cache;

/**
 * Answers a free-text question in the website sales chat with the AI (D78), lightly: a cached prompt,
 * the last few messages, the lead's details as facts, low effort, a small output cap and few tool
 * rounds. Buttons and forms never come here.
 */
class SalesAssistant
{
    public function __construct(private AiProvider $ai, private SalesBrain $brain) {}

    /** The AI may answer: a key is set, this chat and today's budget have room. */
    public function available(SalesChat $chat): bool
    {
        return $this->ai->isConfigured()
            && $chat->ai_replies < (int) config('sales.ai.replies_per_chat', 30)
            && self::spentToday() < (int) config('sales.ai.daily_token_budget', 2_000_000);
    }

    public static function spentToday(): int
    {
        return (int) Cache::get('sales-chat-tokens:'.now()->toDateString(), 0);
    }

    /**
     * @param  callable(string, array<string, mixed>): array{ok: bool, content: string}  $runTool
     * @return string|null the answer, or null when the AI couldn't help (the caller falls back)
     */
    public function answer(SalesChat $chat, callable $runTool): ?string
    {
        $messages = $this->history($chat);
        $steps = 0;
        $usage = ['input' => 0, 'output' => 0, 'cache_read' => 0];
        $text = null;

        try {
            while (true) {
                $response = $this->ai->complete(new AiRequest(
                    $this->brain->instructions(),
                    $this->brain->context($chat->fresh()),
                    $messages,
                    self::tools(),
                    effort: (string) config('sales.ai.effort', 'low'),
                    model: config('sales.ai.model') ?: null,
                    maxTokens: (int) config('sales.ai.max_tokens', 2000),
                ));
                foreach ($usage as $k => $v) {
                    $usage[$k] = $v + (int) $response->usage[$k];
                }
                $steps++;

                if (! $response->wantsTools()) {
                    $text = in_array($response->stopReason, ['refusal', 'max_tokens'], true) ? null : ($response->text !== '' ? $response->text : null);
                    break;
                }
                if ($steps >= (int) config('sales.ai.max_steps', 4)) {
                    $text = $response->text !== '' ? $response->text : null;
                    break;
                }

                $messages[] = ['role' => 'assistant', 'content' => $response->providerContent];
                $results = [];
                foreach ($response->toolCalls as $call) {
                    $result = $runTool($call['name'], $call['input']);
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $call['id'], 'content' => $result['content'], 'is_error' => ! $result['ok']];
                }
                $messages[] = ['role' => 'user', 'content' => $results];
            }
        } catch (AiUnavailable $e) {
            report($e);
        } finally {
            $chat->increment('ai_replies', $text === null ? 0 : 1, [
                'input_tokens' => $chat->input_tokens + $usage['input'],
                'output_tokens' => $chat->output_tokens + $usage['output'],
                'cache_read_tokens' => $chat->cache_read_tokens + $usage['cache_read'],
            ]);
            $key = 'sales-chat-tokens:'.now()->toDateString();
            Cache::add($key, 0, now()->addDays(2));
            Cache::increment($key, $usage['input'] + $usage['output']);
        }

        return $text;
    }

    /** @return list<array{role: string, content: string}> */
    private function history(SalesChat $chat): array
    {
        $rows = $chat->messages()->latest('id')->limit((int) config('sales.ai.history', 12))->get()->reverse()->values();
        $messages = [];
        foreach ($rows as $row) {
            $role = $row->author === 'visitor' ? 'user' : 'assistant';
            $body = $row->author === 'team' ? '[SureHelp team member] '.$row->body : $row->body;
            if ($messages !== [] && end($messages)['role'] === $role) {
                $messages[array_key_last($messages)]['content'] .= "\n\n".$body;
            } else {
                $messages[] = ['role' => $role, 'content' => $body];
            }
        }
        while ($messages !== [] && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }

        return $messages;
    }

    /** @return list<array{name: string, description: string, input_schema: array<string, mixed>}> */
    public static function tools(): array
    {
        $text = fn (string $description) => ['type' => 'string', 'description' => $description];

        return [
            [
                'name' => 'save_lead_details',
                'description' => 'Remember what the visitor told you about themselves or their business. Include only fields they actually stated.',
                'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'name' => $text('Their name'),
                    'email' => $text('Their email address'),
                    'phone' => $text('Their phone number'),
                    'company' => $text('Business name'),
                    'industry' => $text('Kind of business, e.g. plumbing, dental, law firm'),
                    'team_size' => $text('People in the business'),
                    'monthly_calls' => $text('Roughly how many calls a month, as they said it'),
                    'current_solution' => $text('How they handle calls today'),
                    'needs' => $text('What they want help with, in a few words'),
                    'timeline' => $text('When they want to start'),
                    'plan' => $text('Plan they are interested in'),
                ]],
            ],
            [
                'name' => 'offer_demo_times',
                'description' => 'Show the visitor open times for a 30-minute demo call as buttons. Returns the times shown.',
                'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'from_date' => $text('Earliest day they want, YYYY-MM-DD, if they said one'),
                    'part_of_day' => ['type' => 'string', 'enum' => ['any', 'morning', 'afternoon', 'evening']],
                ]],
            ],
            [
                'name' => 'book_demo',
                'description' => 'Book a demo at one of the offered times (exact starts_at). Needs their name and email; if missing, the contact form is shown and the booking completes when they send it.',
                'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['starts_at'], 'properties' => [
                    'starts_at' => $text('starts_at of the chosen time, exactly as offered'),
                ]],
            ],
            [
                'name' => 'show_contact_form',
                'description' => 'Show a short form for their name, email, phone (optional) and company, e.g. so the team can follow up.',
                'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['reason'], 'properties' => [
                    'reason' => $text('Why, in a few words the visitor will see, e.g. "So our team can send you a quote"'),
                ]],
            ],
            [
                'name' => 'hand_over_to_team',
                'description' => 'Pass the chat to a person on the SureHelp team, who replies here and by email.',
                'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['reason'], 'properties' => [
                    'reason' => $text('What the team needs to know'),
                ]],
            ],
        ];
    }
}
