<?php

namespace Tests\Feature;

use App\Exceptions\AiUnavailable;
use App\Livewire\Admin\Connections;
use App\Models\PlatformSecret;
use App\Models\User;
use App\Services\Ai\AiProviders;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Providers\ClaudeProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Connections\Connections as Store;
use App\Services\Social\SocialManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin › Connections (D104): AI and social app keys entered in the admin console, encrypted,
 * applied over .env, and ChatGPT as a second AI provider behind the same interface.
 */
class ConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'super_admin'): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        if ($role !== 'super_admin') {
            $user->syncRoles([$role]);
        }

        return $user;
    }

    public function test_only_super_admins_open_connections(): void
    {
        $admin = $this->staff();
        $this->enableTwoFactor($admin);
        $this->actingAs($admin)->get(route('admin.connections'))->assertOk()->assertSee('Claude API key');
        $this->actingAs($admin)->get(route('admin.connections', ['tab' => 'social']))->assertOk()->assertSee('/admin/social/connect/x/callback');

        $manager = $this->staff('operations_manager');
        $this->enableTwoFactor($manager);
        $this->actingAs($manager)->get(route('admin.connections'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'client', 'is_active' => true]))->get(route('admin.connections'))->assertForbidden();
    }

    public function test_keys_are_saved_encrypted_applied_and_never_shown_again(): void
    {
        config(['ai.openai.api_key' => null, 'social.connectors.meta.client_id' => null]);
        $this->actingAs($this->staff());

        Livewire::test(Connections::class)
            ->set('values.openai_api_key', 'sk-test-secret-1234')
            ->set('values.openai_model', 'gpt-test-model')
            ->set('values.ai_provider', 'openai')
            ->call('save', 'ai')
            ->assertSet('values.openai_api_key', '')   // cleared from the page straight away
            ->assertSee('Saved: ••••1234');

        $raw = DB::table('platform_secrets')->where('key', 'openai_api_key')->value('value');
        $this->assertStringNotContainsString('sk-test-secret', $raw, 'encrypted at rest');
        $this->assertSame('sk-test-secret-1234', PlatformSecret::find('openai_api_key')->value);
        $this->assertSame('sk-test-secret-1234', config('ai.openai.api_key'));
        $this->assertSame('openai', config('ai.provider'));

        // A later request applies them over .env.
        config(['ai.openai.api_key' => 'from-env', 'ai.provider' => 'claude']);
        app(Store::class)->apply();
        $this->assertSame('sk-test-secret-1234', config('ai.openai.api_key'));
        $this->assertInstanceOf(OpenAiProvider::class, app(AiProviders::class)->make());

        // Saving again with the secret left blank keeps it; the audit log names the fields, never the values.
        Livewire::test(Connections::class)->set('values.openai_model', 'gpt-other')->call('save', 'ai');
        $this->assertSame('sk-test-secret-1234', PlatformSecret::find('openai_api_key')->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'connections.saved']);
        $this->assertStringNotContainsString('sk-test-secret', (string) DB::table('audit_logs')->pluck('new_values')->implode(' '));

        Livewire::test(Connections::class)->call('remove', 'openai_api_key');
        $this->assertNull(PlatformSecret::find('openai_api_key'));
    }

    public function test_the_cache_keeps_saved_keys_encrypted(): void
    {
        config(['cache.default' => 'database']);
        $this->actingAs($this->staff());
        Livewire::test(Connections::class)->set('values.openai_api_key', 'sk-test-secret-1234')->call('save', 'ai');

        $this->assertSame('sk-test-secret-1234', app(Store::class)->saved()['openai_api_key']);
        $this->assertGreaterThan(0, DB::table('cache')->count());
        $this->assertStringNotContainsString('sk-test-secret', (string) DB::table('cache')->pluck('value')->implode(' '));
    }

    public function test_social_app_credentials_from_the_admin_turn_networks_on(): void
    {
        config(['social.connectors.linkedin.client_id' => null, 'social.connectors.linkedin.client_secret' => null]);
        $this->actingAs($this->staff());
        Livewire::test(Connections::class)
            ->set('values.linkedin_client_id', '86abc')->set('values.linkedin_client_secret', 'li-secret')
            ->call('save', 'linkedin')->assertDispatched('toast', type: 'success');

        $this->assertSame('86abc', config('social.connectors.linkedin.client_id'));
        $this->assertTrue(app(SocialManager::class)->connector('linkedin')->isConfigured());
    }

    public function test_testing_a_key_lists_its_models(): void
    {
        Http::fake(['api.openai.com/v1/models' => Http::sequence()
            ->push(['data' => [['id' => 'gpt-b'], ['id' => 'gpt-a'], ['id' => 'gpt-image-x']]])
            ->push(['error' => ['message' => 'Incorrect API key provided']], 401)]);
        $this->actingAs($this->staff());

        Livewire::test(Connections::class)->set('values.openai_api_key', 'sk-typed')->call('testAi', 'openai')
            ->assertSet('models.openai', ['gpt-a', 'gpt-b', 'gpt-image-x'])->assertDispatched('toast', type: 'success');
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer sk-typed'));

        Livewire::test(Connections::class)->set('values.openai_api_key', 'sk-bad')->call('testAi', 'openai')->assertDispatched('toast', type: 'warning');
    }

    public function test_chatgpt_answers_through_the_same_interface_with_tools_and_structured_output(): void
    {
        config(['ai.openai.api_key' => 'sk-live', 'ai.openai.model' => 'gpt-test', 'ai.provider' => 'openai']);
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push(['status' => 'completed', 'output' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
                ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'book', 'arguments' => '{"day":"Mon"}'],
            ], 'usage' => ['input_tokens' => 50, 'output_tokens' => 10, 'input_tokens_details' => ['cached_tokens' => 20]]])
            ->push(['status' => 'completed', 'output' => [
                ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Booked for Monday.']]],
            ], 'usage' => ['input_tokens' => 70, 'output_tokens' => 5]]),
        ]);

        $ai = app(AiProvider::class);
        $this->assertInstanceOf(OpenAiProvider::class, $ai);
        $tools = [['name' => 'book', 'description' => 'Book it', 'input_schema' => ['type' => 'object', 'properties' => ['day' => ['type' => 'string']]]]];
        $first = $ai->complete(new AiRequest('Be kind.', 'Now: Monday', [['role' => 'user', 'content' => 'Book me in']], $tools, effort: 'xhigh'));
        $this->assertSame('tool_use', $first->stopReason);
        $this->assertSame([['id' => 'call_1', 'name' => 'book', 'input' => ['day' => 'Mon']]], $first->toolCalls);
        $this->assertSame(['input' => 50, 'output' => 10, 'cache_read' => 20], $first->usage);

        $second = $ai->complete(new AiRequest('Be kind.', 'Now: Monday', [
            ['role' => 'user', 'content' => 'Book me in'],
            ['role' => 'assistant', 'content' => $first->providerContent],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => 'ok', 'is_error' => false]]],
        ], $tools));
        $this->assertSame('end_turn', $second->stopReason);
        $this->assertSame('Booked for Monday.', $second->text);

        Http::assertSent(fn (Request $r) => $r['model'] === 'gpt-test' && $r['instructions'] === "Be kind.\n\nNow: Monday"
            && $r['tools'][0]['type'] === 'function' && $r['reasoning']['effort'] === 'high' && $r['store'] === false);
        Http::assertSent(fn (Request $r) => collect($r['input'])->contains(fn ($i) => ($i['type'] ?? null) === 'function_call_output' && $i['call_id'] === 'call_1')
            && collect($r['input'])->contains(fn ($i) => ($i['type'] ?? null) === 'function_call'));
    }

    public function test_structured_output_and_models_without_reasoning(): void
    {
        config(['ai.openai.api_key' => 'sk-live', 'ai.openai.model' => 'gpt-plain']);
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push(['error' => ['message' => "Unsupported parameter: 'reasoning.effort' is not supported with this model."]], 400)
            ->push(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"title":"Hi"}']]]]])
            ->push(['error' => ['message' => 'Incorrect API key']], 401),
        ]);
        $reply = app(OpenAiProvider::class)->complete(new AiRequest('Write.', '', [['role' => 'user', 'content' => 'Go']],
            outputSchema: ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]], model: 'claude-haiku-5-5'));
        $this->assertSame(['title' => 'Hi'], json_decode($reply->text, true));
        Http::assertSent(fn (Request $r) => $r['text']['format']['type'] === 'json_schema' && $r['model'] === 'gpt-plain' && ! isset($r['reasoning']));

        $this->expectException(AiUnavailable::class);
        app(OpenAiProvider::class)->complete(new AiRequest('Write.', '', [['role' => 'user', 'content' => 'Go']]));
    }

    public function test_the_social_writer_can_differ_from_the_main_one(): void
    {
        config(['ai.provider' => 'claude', 'ai.social_provider' => 'openai']);
        $this->assertInstanceOf(ClaudeProvider::class, app(AiProviders::class)->make());
        $this->assertInstanceOf(OpenAiProvider::class, app(AiProviders::class)->forSocial());
        config(['ai.social_provider' => null]);
        $this->assertSame(app(AiProvider::class), app(AiProviders::class)->forSocial());
    }
}
