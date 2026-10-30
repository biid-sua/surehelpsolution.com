<?php

namespace App\Livewire\Admin;

use App\Exceptions\AiUnavailable;
use App\Livewire\Concerns\PlatformAdminOnly;
use App\Services\Ai\Providers\ClaudeProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Connections\Connections as Store;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin › Connections (D104): AI keys and models, which AI writes, and the social networks' app
 * credentials. Secrets are typed in, saved encrypted and never shown again (only their last four
 * characters). Super admins only: these keys can spend money and post as SureHelp.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Connections')]
class Connections extends Component
{
    use PlatformAdminOnly;

    /** @var array<string, mixed> Field values being edited; secrets start blank. */
    public array $values = [];

    /** @var array<string, list<string>> Models found by "Test", per provider. */
    public array $models = [];

    public function mount(Store $store): void
    {
        $this->authorize('integrations.manage');
        foreach (Store::fields() as $key => $field) {
            $this->values[$key] = ($field['secret'] ?? false) ? '' : (($field['type'] ?? 'text') === 'bool' ? (bool) $store->current($key) : (string) $store->current($key));
        }
    }

    public function save(string $group, Store $store): void
    {
        $this->authorize('integrations.manage');
        $fields = Store::groups()[$group]['fields'] ?? abort(404);
        $this->validate(collect($fields)->mapWithKeys(fn ($f, $key) => ['values.'.$key => match ($f['type'] ?? 'text') {
            'bool' => ['boolean'],
            'select' => ['nullable', 'in:'.implode(',', array_keys($f['options']))],
            default => ['nullable', 'string', 'max:500'],
        }])->all());

        $changed = $store->save(array_intersect_key($this->values, $fields), auth()->user());
        foreach ($fields as $key => $f) {
            if ($f['secret'] ?? false) {
                $this->values[$key] = '';
            }
        }
        $this->dispatch('toast', type: 'success', message: $changed === [] ? 'Nothing changed.' : Store::groups()[$group]['label'].' saved.');
    }

    public function remove(string $key, Store $store): void
    {
        $this->authorize('integrations.manage');
        abort_unless(isset(Store::fields()[$key]), 404);
        $store->remove($key, auth()->user());
        $this->values[$key] = '';
        $this->dispatch('toast', type: 'success', message: Store::fields()[$key]['label'].' removed.');
    }

    /** Check an AI key (the one typed, else the saved one) and list its models. */
    public function testAi(string $provider): void
    {
        $this->authorize('integrations.manage');
        if (RateLimiter::tooManyAttempts('connections-test:'.auth()->id(), 10)) {
            $this->dispatch('toast', type: 'warning', message: 'Please wait a minute before testing again.');

            return;
        }
        RateLimiter::hit('connections-test:'.auth()->id(), 60);

        $typed = trim((string) ($this->values[$provider === 'openai' ? 'openai_api_key' : 'anthropic_api_key'] ?? ''));
        $client = $provider === 'openai' ? app(OpenAiProvider::class) : app(ClaudeProvider::class);
        $saved = $provider === 'openai' ? config('ai.openai.api_key') : config('ai.anthropic.api_key');
        if ($typed === '' && blank($saved)) {
            $this->addError('values.'.($provider === 'openai' ? 'openai_api_key' : 'anthropic_api_key'), 'Enter the API key first.');

            return;
        }

        try {
            $this->models[$provider] = $client->listModels($typed !== '' ? $typed : null);
        } catch (AiUnavailable $e) {
            $this->dispatch('toast', type: 'warning', message: 'That key didn\'t work: '.str($e->getMessage())->limit(160));

            return;
        }
        $this->dispatch('toast', type: 'success', message: 'Connected. '.count($this->models[$provider]).' models available: pick one below, then save.');
    }

    /** Meta lets us check an app ID and secret on their own (an app access token). */
    public function testMeta(): void
    {
        $this->authorize('integrations.manage');
        $id = trim((string) $this->values['meta_client_id']) ?: (string) config('social.connectors.meta.client_id');
        $secret = trim((string) $this->values['meta_client_secret']) ?: (string) config('social.connectors.meta.client_secret');
        if ($id === '' || $secret === '') {
            $this->dispatch('toast', type: 'warning', message: 'Enter the App ID and App secret first.');

            return;
        }
        $response = rescue(fn () => Http::timeout(15)->get('https://graph.facebook.com/oauth/access_token', [
            'client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'client_credentials',
        ]), null, report: false);
        $ok = $response?->successful() && $response->json('access_token');
        $this->dispatch('toast', type: $ok ? 'success' : 'warning', message: $ok
            ? 'Meta accepted this app ID and secret.'
            : 'Meta refused them: '.($response?->json('error.message') ?? 'no answer'));
    }

    public function render(Store $store): View
    {
        $sources = [];
        foreach (Store::fields() as $key => $field) {
            $sources[$key] = $store->isSaved($key) ? 'saved' : (filled($store->current($key)) ? 'env' : null);
        }

        return view('livewire.admin.connections', [
            'groups' => Store::groups(),
            'sources' => $sources,
            'masked' => collect(Store::fields())->filter(fn ($f) => $f['secret'] ?? false)->map(fn ($f, $key) => $store->masked($key))->all(),
            'callbacks' => collect(['meta', 'linkedin', 'google', 'youtube', 'x'])->mapWithKeys(fn ($c) => [$c => [
                'business' => url('/app/social/connect/'.$c.'/callback'),
                'surehelp' => url('/admin/social/connect/'.$c.'/callback'),
            ]])->all(),
        ]);
    }
}
