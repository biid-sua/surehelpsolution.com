<?php

namespace App\Services\Ai\Data;

/**
 * A vendor-neutral model call.
 *
 * - `instructions` is stable per business (cached by the provider): it must not contain the time
 *   or anything that changes from message to message.
 * - `context` is the volatile part (current time, opening status, this customer's details).
 * - `messages`: role "user" / "assistant". Content is a string, a list of tool results
 *   (['type' => 'tool_result', 'tool_use_id', 'content', 'is_error']), or the provider's own
 *   content from an earlier response (passed back unchanged).
 */
final class AiRequest
{
    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     */
    public function __construct(
        public readonly string $instructions,
        public readonly string $context,
        public readonly array $messages,
        public readonly array $tools = [],
    ) {}
}
