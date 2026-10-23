<?php

namespace App\Livewire\Client\Business;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\ApiKey;
use App\Models\WebhookEndpoint;
use App\Services\Integrations\Webhooks;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Business › API & webhooks (D87): keys for the business API, and endpoints that receive events, for
 * n8n, Zapier, Make or the business's own code.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('API & webhooks')]
class Integrations extends Component
{
    use ScopedToOrganization;

    public string $keyName = '';

    public bool $keyWrite = false;

    /** The new key, shown once. */
    public ?string $newKey = null;

    public string $url = '';

    /** @var list<string> */
    public array $events = ['appointment.booked', 'customer.created', 'call.logged'];

    public ?string $editing = null;   // endpoint ulid whose deliveries are open

    public ?string $revealed = null;  // endpoint ulid whose secret is shown

    public function mount(): void
    {
        $this->authorize('integrations.manage', $this->organization());
    }

    public function createKey(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('integrations.manage', $organization);
        $this->validate(['keyName' => ['required', 'string', 'max:80']], [], ['keyName' => 'name']);
        if (ApiKey::query()->forOrganization($organization)->whereNull('revoked_at')->count() >= 10) {
            $this->addError('keyName', 'At most 10 keys. Revoke one you no longer use.');

            return;
        }
        [$key, $secret] = ApiKey::issue($organization, auth()->user(), trim($this->keyName), $this->keyWrite);
        $audit->record('api_key.created', $key, new: ['name' => $key->name, 'can_write' => $key->can_write], label: $key->name);
        $this->newKey = $secret;
        $this->reset(['keyName', 'keyWrite']);
    }

    public function revokeKey(string $ulid, Audit $audit): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $key = ApiKey::query()->forOrganization($this->organization())->where('ulid', $ulid)->whereNull('revoked_at')->firstOrFail();
        $key->forceFill(['revoked_at' => now()])->save();
        $audit->record('api_key.revoked', $key, label: $key->name);
        $this->newKey = null;
    }

    public function addEndpoint(Audit $audit, Webhooks $webhooks): void
    {
        $organization = $this->organization();
        $this->authorize('integrations.manage', $organization);
        $this->validate([
            'url' => ['required', 'string', 'max:500', 'url:https'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(WebhookEndpoint::EVENTS))],
        ], ['events.required' => 'Choose at least one event.', 'events.min' => 'Choose at least one event.', 'url.url' => 'Use an https:// address.']);
        if (is_string($problem = $webhooks->target($this->url))) {
            $this->addError('url', $problem);

            return;
        }
        if (WebhookEndpoint::query()->forOrganization($organization)->count() >= 10) {
            $this->addError('url', 'At most 10 webhook addresses.');

            return;
        }
        $endpoint = WebhookEndpoint::create(['organization_id' => $organization->id, 'url' => trim($this->url), 'events' => $this->events]);
        $audit->record('webhook.created', $endpoint, new: ['url' => $endpoint->url, 'events' => $endpoint->events], label: Str::limit($endpoint->url, 60));
        $this->revealed = $endpoint->ulid;
        $this->reset('url');
        $this->dispatch('toast', type: 'success', message: 'Webhook added. Copy its signing secret to check our signature.');
    }

    public function toggleEvent(string $ulid, string $event): void
    {
        $this->authorize('integrations.manage', $this->organization());
        abort_unless(array_key_exists($event, WebhookEndpoint::EVENTS), 422);
        $endpoint = $this->endpoint($ulid);
        $events = in_array($event, $endpoint->events, true) ? array_values(array_diff($endpoint->events, [$event])) : [...$endpoint->events, $event];
        if ($events === []) {
            $this->dispatch('toast', type: 'warning', message: 'Keep at least one event, or remove the webhook.');

            return;
        }
        $endpoint->forceFill(['events' => $events])->save();
    }

    public function toggleActive(string $ulid): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $endpoint = $this->endpoint($ulid);
        $endpoint->forceFill(['is_active' => ! $endpoint->is_active, 'failures' => 0, 'disabled_reason' => null])->save();
    }

    public function test(string $ulid, Webhooks $webhooks): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $delivery = $webhooks->test($this->endpoint($ulid));
        $this->editing = $ulid;
        $this->dispatch('toast', type: $delivery->status === 'delivered' ? 'success' : 'warning',
            message: $delivery->status === 'delivered' ? 'Test delivered (HTTP '.$delivery->response_code.').' : 'Test failed: '.$delivery->error);
    }

    public function rotate(string $ulid, Audit $audit): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $endpoint = $this->endpoint($ulid);
        $endpoint->forceFill(['secret' => 'whsec_'.Str::random(40)])->save();
        $audit->record('webhook.secret_rotated', $endpoint, label: Str::limit($endpoint->url, 60));
        $this->revealed = $ulid;
    }

    public function remove(string $ulid, Audit $audit): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $endpoint = $this->endpoint($ulid);
        $audit->record('webhook.deleted', $endpoint, old: ['url' => $endpoint->url], label: Str::limit($endpoint->url, 60));
        $endpoint->delete();
    }

    public function show(string $ulid): void
    {
        $this->editing = $this->editing === $ulid ? null : $ulid;
    }

    private function endpoint(string $ulid): WebhookEndpoint
    {
        return WebhookEndpoint::query()->forOrganization($this->organization())->where('ulid', $ulid)->firstOrFail();
    }

    public function render(): View
    {
        $organization = $this->organization();

        return view('livewire.client.business.integrations', [
            'keys' => ApiKey::query()->forOrganization($organization)->with('user:id,name')->latest()->get(),
            'endpoints' => WebhookEndpoint::query()->forOrganization($organization)
                ->with(['deliveries' => fn ($q) => $q->latest()->limit(10)])->latest()->get(),
            'allEvents' => WebhookEndpoint::EVENTS,
            'apiBase' => url('/api/v1/client'),
        ]);
    }
}
