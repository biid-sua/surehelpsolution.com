<?php

namespace App\Services\Ai\Data;

/**
 * What came back from one model call.
 *
 * `providerContent` is the vendor's own content blocks, passed back unchanged as the assistant turn
 * when the loop continues (some models require their reasoning blocks to be returned as they were).
 */
final class AiResponse
{
    /**
     * @param  'end_turn'|'tool_use'|'max_tokens'|'refusal'|string  $stopReason
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $toolCalls
     * @param  array{input: int, output: int, cache_read: int}  $usage
     */
    public function __construct(
        public readonly string $stopReason,
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly mixed $providerContent,
        public readonly array $usage = ['input' => 0, 'output' => 0, 'cache_read' => 0],
    ) {}

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [] && $this->stopReason === 'tool_use';
    }
}
