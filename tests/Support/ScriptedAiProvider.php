<?php

namespace Tests\Support;

use App\Exceptions\AiUnavailable;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use RuntimeException;

/**
 * A stand-in for the AI vendor in tests: plays back scripted responses and records every request,
 * so tests check what the assistant was told and which tools it was offered, without any network call.
 */
class ScriptedAiProvider implements AiProvider
{
    /** @var list<AiResponse|\Throwable> */
    private array $script = [];

    /** @var list<AiRequest> */
    public array $requests = [];

    public bool $configured = true;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function model(): string
    {
        return 'scripted-test-model';
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->script) ?? throw new RuntimeException('The AI was called more often than the test scripted.');

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function reply(string $text, string $stopReason = 'end_turn'): self
    {
        $this->script[] = new AiResponse($stopReason, $text, [], [['type' => 'text', 'text' => $text]], ['input' => 1000, 'output' => 50, 'cache_read' => 900]);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function tool(string $name, array $input = []): self
    {
        $id = 'toolu_'.count($this->script).'_'.$name;
        $this->script[] = new AiResponse('tool_use', '', [['id' => $id, 'name' => $name, 'input' => $input]],
            [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]], ['input' => 1000, 'output' => 30, 'cache_read' => 900]);

        return $this;
    }

    public function fail(): self
    {
        $this->script[] = new AiUnavailable('Overloaded');

        return $this;
    }

    /** Tool names offered on the given request. @return list<string> */
    public function toolNames(int $request = 0): array
    {
        return array_column($this->requests[$request]->tools ?? [], 'name');
    }

    /** The tool results sent back on the given request (from the last message). @return list<array<string, mixed>> */
    public function toolResults(int $request): array
    {
        $messages = $this->requests[$request]->messages;
        $last = end($messages);

        return is_array($last['content'] ?? null) ? $last['content'] : [];
    }

    public function remaining(): int
    {
        return count($this->script);
    }
}
