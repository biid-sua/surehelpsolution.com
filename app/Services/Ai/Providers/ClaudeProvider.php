<?php

namespace App\Services\Ai\Providers;

use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use App\Exceptions\AiUnavailable;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use Psr\Http\Client\ClientInterface;

/**
 * Claude through the official Anthropic PHP SDK (D38).
 *
 * - The business's instructions are one cached system block; the volatile context follows it
 *   uncached, so the cache prefix (tools + instructions) stays identical between messages.
 * - Thinking is left to the model (adaptive; it can't be disabled on this model), effort is set
 *   explicitly, and tool choice stays "auto" (forced tool use is not supported).
 * - A refused request is retried server-side on Anthropic's default fallback model.
 */
class ClaudeProvider implements AiProvider
{
    private ?Client $client = null;

    private ?ClientInterface $transporter = null;

    public function isConfigured(): bool
    {
        return filled(config('ai.anthropic.api_key'));
    }

    public function model(): string
    {
        return (string) config('ai.anthropic.model', 'claude-opus-5-5');
    }

    public function complete(AiRequest $request): AiResponse
    {
        if (! $this->isConfigured()) {
            throw new AiUnavailable('No Anthropic API key is set.');
        }

        $system = [['type' => 'text', 'text' => $request->instructions, 'cacheControl' => ['type' => 'ephemeral']]];
        if (trim($request->context) !== '') {
            $system[] = ['type' => 'text', 'text' => $request->context];
        }

        $fallbacks = (bool) config('ai.anthropic.fallbacks', true);

        try {
            $message = $this->client()->beta->messages->create(
                maxTokens: (int) config('ai.anthropic.max_tokens', 16000),
                messages: array_map(fn (array $m) => $this->message($m), $request->messages),
                model: $this->model(),
                fallbacks: $fallbacks ? 'default' : null,
                outputConfig: ['effort' => (string) config('ai.anthropic.effort', 'medium')],
                system: $system,
                toolChoice: $request->tools === [] ? null : ['type' => 'auto'],
                tools: $request->tools === [] ? null : array_map(fn (array $t) => [
                    'name' => $t['name'],
                    'description' => $t['description'],
                    'inputSchema' => $t['input_schema'],
                ], $request->tools),
                betas: $fallbacks ? ['server-side-fallback-2026-07-01'] : null,
            );
        } catch (APIException $e) {
            throw new AiUnavailable('Claude could not answer: '.$e->getMessage(), previous: $e);
        }

        $text = '';
        $calls = [];
        foreach ($message->content as $block) {
            if ($block instanceof BetaToolUseBlock) {
                $calls[] = ['id' => $block->id, 'name' => $block->name, 'input' => $block->input];
            } elseif ($block instanceof BetaTextBlock) {
                $text .= $block->text;
            }
        }

        return new AiResponse(
            (string) $message->stopReason,
            trim($text),
            $calls,
            $message->content,
            [
                'input' => $message->usage->inputTokens,
                'output' => $message->usage->outputTokens,
                'cache_read' => (int) $message->usage->cacheReadInputTokens,
            ],
        );
    }

    /**
     * Neutral message → SDK shape (camelCase keys). Provider content from an earlier turn is passed back as it came.
     *
     * @param  array{role: string, content: mixed}  $message
     * @return array<string, mixed>
     */
    private function message(array $message): array
    {
        $content = $message['content'];

        if (is_array($content)) {
            $content = array_map(fn ($block) => is_array($block) && ($block['type'] ?? null) === 'tool_result'
                ? ['type' => 'tool_result', 'toolUseID' => $block['tool_use_id'], 'content' => (string) $block['content'], 'isError' => (bool) ($block['is_error'] ?? false)]
                : $block, $content);
        }

        return ['role' => $message['role'], 'content' => $content];
    }

    /** Tests pass a PSR-18 client that records the exact HTTP request instead of calling Anthropic. */
    public function usingTransport(ClientInterface $transporter): self
    {
        $this->transporter = $transporter;
        $this->client = null;

        return $this;
    }

    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: (string) config('ai.anthropic.api_key'),
            requestOptions: array_filter([
                'timeout' => 90.0,       // a customer is waiting, but a tool-using reply can take a while
                'maxRetries' => 2,       // the SDK retries 429 / 5xx / network errors with backoff
                'transporter' => $this->transporter,
            ], fn ($v) => $v !== null),
        );
    }
}
