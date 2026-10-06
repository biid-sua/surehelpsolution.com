<?php

namespace Tests\Feature;

use App\Exceptions\AiUnavailable;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Providers\ClaudeProvider;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

/**
 * The Claude adapter through the real Anthropic PHP SDK, with only the network replaced:
 * checks the exact request sent to Anthropic and how responses are read (D38).
 */
class ClaudeProviderTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $sent = [];

    /** @var list<ResponseInterface> */
    private array $replies = [];

    private function provider(): ClaudeProvider
    {
        config(['ai.anthropic.api_key' => 'sk-ant-test', 'ai.anthropic.model' => 'claude-opus-5-5', 'ai.anthropic.effort' => 'medium', 'ai.anthropic.fallbacks' => true]);
        $test = $this;

        return (new ClaudeProvider)->usingTransport(new class($test) implements ClientInterface
        {
            public function __construct(private readonly ClaudeProviderTest $test) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->test->record($request);
            }
        });
    }

    public function record(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = $request;

        return array_shift($this->replies) ?? new Response(500, [], '{}');
    }

    private function reply(array $content, string $stop): void
    {
        $this->replies[] = new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_'.count($this->replies), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => $content, 'stop_reason' => $stop, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 80, 'cache_read_input_tokens' => 1000, 'cache_creation_input_tokens' => 0],
        ]));
    }

    /** @return array<string, mixed> */
    private function body(int $i): array
    {
        return json_decode((string) $this->sent[$i]->getBody(), true);
    }

    public function test_a_tool_round_trip_is_sent_in_anthropics_format(): void
    {
        $provider = $this->provider();
        $tools = [['name' => 'get_available_times', 'description' => 'Free times', 'input_schema' => ['type' => 'object', 'properties' => ['date' => ['type' => 'string']]]]];

        $this->reply([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_available_times', 'input' => ['date' => '2026-10-06']]], 'tool_use');
        $first = $provider->complete(new AiRequest('STABLE INSTRUCTIONS', 'Right now: Monday', [['role' => 'user', 'content' => 'Tuesday?']], $tools));

        $this->assertTrue($first->wantsTools());
        $this->assertSame([['id' => 'toolu_1', 'name' => 'get_available_times', 'input' => ['date' => '2026-10-06']]], $first->toolCalls);
        $this->assertSame(['input' => 1200, 'output' => 80, 'cache_read' => 1000], $first->usage);

        $request = $this->sent[0];
        $this->assertSame('https://api.anthropic.com/v1/messages?beta=true', (string) $request->getUri());
        $this->assertSame('sk-ant-test', $request->getHeaderLine('x-api-key'));
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));
        $body = $this->body(0);
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame(['effort' => 'medium'], $body['output_config']);
        $this->assertSame(['type' => 'auto'], $body['tool_choice']);
        $this->assertSame('STABLE INSTRUCTIONS', $body['system'][0]['text']);
        $this->assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control'], 'the stable instructions are cached');
        $this->assertSame('Right now: Monday', $body['system'][1]['text']);
        $this->assertArrayNotHasKey('cache_control', $body['system'][1], 'the volatile context is not');
        $this->assertSame('get_available_times', $body['tools'][0]['name']);
        $this->assertArrayHasKey('input_schema', $body['tools'][0]);
        $this->assertArrayNotHasKey('thinking', $body, 'thinking is left to the model (adaptive)');

        // Second turn: the assistant's blocks go back unchanged, the tool result in Anthropic's shape.
        $this->reply([['type' => 'text', 'text' => 'We have 10:00 or 2:00 on Tuesday.']], 'end_turn');
        $second = $provider->complete(new AiRequest('STABLE INSTRUCTIONS', 'Right now: Monday', [
            ['role' => 'user', 'content' => 'Tuesday?'],
            ['role' => 'assistant', 'content' => $first->providerContent],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '10:00, 14:00', 'is_error' => false]]],
        ], $tools));

        $this->assertSame('We have 10:00 or 2:00 on Tuesday.', $second->text);
        $this->assertSame('end_turn', $second->stopReason);
        $messages = $this->body(1)['messages'];
        $this->assertSame('tool_use', $messages[1]['content'][0]['type']);
        $this->assertSame('toolu_1', $messages[1]['content'][0]['id']);
        $this->assertSame(['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '10:00, 14:00', 'is_error' => false], $messages[2]['content'][0]);
    }

    public function test_api_errors_become_ai_unavailable(): void
    {
        $provider = $this->provider();
        $this->replies = array_fill(0, 3, new Response(400, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'bad']])));

        $this->expectException(AiUnavailable::class);
        $provider->complete(new AiRequest('x', '', [['role' => 'user', 'content' => 'hi']]));
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        config(['ai.anthropic.api_key' => null]);
        $provider = new ClaudeProvider;

        $this->assertFalse($provider->isConfigured());
        $this->expectException(AiUnavailable::class);
        $provider->complete(new AiRequest('x', '', [['role' => 'user', 'content' => 'hi']]));
    }
}
