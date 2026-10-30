<?php

namespace App\Services\Ai\Providers;

use App\Exceptions\AiUnavailable;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * ChatGPT through OpenAI's Responses API (D104), behind the same interface as Claude.
 *
 * - Instructions and context go in `instructions`; tools become function tools; a structured-output
 *   schema becomes `text.format` (json_schema, not strict, since our schemas aren't written for it).
 * - The earlier response's output items are passed back unchanged as the assistant turn, and tool
 *   results become `function_call_output` items, as the API expects.
 * - Effort maps to `reasoning.effort`; a model without reasoning is retried once without it.
 */
class OpenAiProvider implements AiProvider
{
    private const BASE = 'https://api.openai.com/v1';

    public function isConfigured(): bool
    {
        return filled(config('ai.openai.api_key')) && filled(config('ai.openai.model'));
    }

    public function model(): string
    {
        return (string) config('ai.openai.model');
    }

    public function complete(AiRequest $request): AiResponse
    {
        if (! $this->isConfigured()) {
            throw new AiUnavailable('No OpenAI API key or model is set.');
        }

        $instructions = trim($request->instructions."\n\n".$request->context);
        $body = array_filter([
            // Claude-specific model names (agent assist, sales chat) don't apply here.
            'model' => $request->model && ! str_starts_with($request->model, 'claude') ? $request->model : $this->model(),
            'instructions' => $instructions,
            'input' => $this->input($request->messages),
            'max_output_tokens' => $request->maxTokens ?? (int) config('ai.openai.max_tokens', 16000),
            'tools' => $request->tools === [] ? null : array_map(fn (array $t) => [
                'type' => 'function', 'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['input_schema'],
            ], $request->tools),
            'text' => $request->outputSchema === null ? null : ['format' => ['type' => 'json_schema', 'name' => 'output', 'schema' => $request->outputSchema, 'strict' => false]],
            'reasoning' => ['effort' => match ($request->effort) {
                'low' => 'low', 'high', 'xhigh', 'max' => 'high', default => 'medium',
            }],
            'store' => false,
        ], fn ($v) => $v !== null);

        $response = $this->send($body);
        if ($response->status() === 400 && str_contains((string) $response->json('error.message'), 'reasoning')) {
            unset($body['reasoning']);
            $response = $this->send($body);
        }
        if ($response->failed()) {
            throw new AiUnavailable('ChatGPT could not answer: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        $output = (array) $response->json('output', []);
        $text = '';
        $calls = [];
        $refused = false;
        foreach ($output as $item) {
            if (($item['type'] ?? null) === 'function_call') {
                $calls[] = ['id' => (string) $item['call_id'], 'name' => (string) $item['name'], 'input' => (array) (json_decode((string) ($item['arguments'] ?? '{}'), true) ?: [])];
            } elseif (($item['type'] ?? null) === 'message') {
                foreach ((array) ($item['content'] ?? []) as $part) {
                    if (($part['type'] ?? null) === 'output_text') {
                        $text .= $part['text'];
                    } elseif (($part['type'] ?? null) === 'refusal') {
                        $refused = true;
                        $text .= $part['refusal'] ?? '';
                    }
                }
            }
        }

        $stop = match (true) {
            $calls !== [] => 'tool_use',
            $refused => 'refusal',
            $response->json('status') === 'incomplete' && $response->json('incomplete_details.reason') === 'max_output_tokens' => 'max_tokens',
            default => 'end_turn',
        };

        return new AiResponse($stop, trim($text), $calls, $output, [
            'input' => (int) $response->json('usage.input_tokens', 0),
            'output' => (int) $response->json('usage.output_tokens', 0),
            'cache_read' => (int) $response->json('usage.input_tokens_details.cached_tokens', 0),
        ]);
    }

    /**
     * Models this key can use (Admin › Connections fills its dropdowns from this).
     *
     * @return list<string>
     *
     * @throws AiUnavailable
     */
    public function listModels(?string $apiKey = null): array
    {
        try {
            $response = $this->http($apiKey)->get(self::BASE.'/models');
        } catch (ConnectionException $e) {
            throw new AiUnavailable('Couldn\'t reach OpenAI: '.$e->getMessage(), previous: $e);
        }
        if ($response->failed()) {
            throw new AiUnavailable((string) ($response->json('error.message') ?? 'OpenAI answered HTTP '.$response->status()));
        }
        $ids = collect((array) $response->json('data', []))->pluck('id')->filter()->map(fn ($id) => (string) $id)->sort()->values()->all();

        return $ids;
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return list<array<string, mixed>>
     */
    private function input(array $messages): array
    {
        $input = [];
        foreach ($messages as $message) {
            $content = $message['content'];
            if (is_string($content)) {
                $input[] = ['role' => $message['role'], 'content' => $content];

                continue;
            }
            foreach ((array) $content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'tool_result') {
                    $input[] = ['type' => 'function_call_output', 'call_id' => $block['tool_use_id'], 'output' => (string) $block['content']];
                } elseif (is_array($block) && isset($block['type'])) {
                    // Output items from an earlier response (messages, reasoning, function calls), unchanged.
                    $input[] = $block;
                }
            }
        }

        return $input;
    }

    /** @param  array<string, mixed>  $body */
    private function send(array $body): Response
    {
        try {
            return $this->http()->timeout(90)->retry(2, 1000, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError())), throw: false)
                ->post(self::BASE.'/responses', $body);
        } catch (ConnectionException $e) {
            throw new AiUnavailable('Couldn\'t reach OpenAI: '.$e->getMessage(), previous: $e);
        }
    }

    private function http(?string $apiKey = null): PendingRequest
    {
        return Http::withToken((string) ($apiKey ?? config('ai.openai.api_key')))->acceptJson()->asJson();
    }
}
