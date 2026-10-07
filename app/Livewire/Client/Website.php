<?php

namespace App\Livewire\Client;

use App\Jobs\CheckWebsite;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\ChatWidget;
use App\Models\Website as Site;
use App\Services\Websites\WebsiteVerifier;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Connect a website (spec §41B, D44): add the business's sites, prove ownership, see the health and
 * SEO check with plain-language fixes, and choose what the one SureHelp snippet adds to the site.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Website')]
class Website extends Component
{
    use ScopedToOrganization;

    public string $newUrl = '';

    /** The site whose details are open (ULID). */
    #[Url(as: 'site', except: '')]
    public string $open = '';

    /** @var array<string, bool> */
    public array $features = [];

    public bool $needsConfirmation = true;

    public function mount(): void
    {
        $organization = $this->organization();
        $this->authorize('integrations.view', $organization);
        $widget = ChatWidget::for($organization);
        $this->features = $widget->enabledFeatures();
        $this->needsConfirmation = $widget->bookings_need_confirmation;
    }

    public function add(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('integrations.manage', $organization);
        $this->resetValidation();

        $normalised = Site::normalise($this->newUrl);
        if (! $normalised) {
            $this->addError('newUrl', 'Enter your website address, like riverplumbing.com.');

            return;
        }
        [$url, $host] = $normalised;
        if (Site::query()->forOrganization($organization)->where('host', $host)->exists()) {
            $this->addError('newUrl', 'This website is already on your list.');

            return;
        }
        if (Site::query()->forOrganization($organization)->count() >= Site::MAX_PER_BUSINESS) {
            $this->addError('newUrl', 'You can connect up to '.Site::MAX_PER_BUSINESS.' websites.');

            return;
        }

        $site = Site::create(['organization_id' => $organization->id, 'url' => $url, 'host' => $host, 'added_by' => auth()->id()]);
        $audit->record('website.added', $site, new: ['host' => $host], label: $host);
        $this->reset('newUrl');
        $this->open = $site->ulid;
        $this->dispatch('toast', type: 'success', message: "Added {$host}. Now prove it's yours.");
    }

    public function verify(string $ulid, WebsiteVerifier $verifier): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $site = $this->site($ulid);
        if ($this->tooSoon('verify:'.$site->id, 10)) {
            return;
        }

        $result = $verifier->verify($site);
        $this->dispatch('toast', type: $result['ok'] ? 'success' : 'warning', message: $result['message']);
        if ($result['ok']) {
            CheckWebsite::dispatch($site->id);
        }
    }

    public function check(string $ulid): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $site = $this->site($ulid);
        abort_unless($site->isVerified(), 403);
        if ($this->tooSoon('check:'.$site->id, 3)) {
            return;
        }

        CheckWebsite::dispatch($site->id);
        $this->dispatch('toast', type: 'success', message: 'Checking your site. Results appear here in a minute or two.');
    }

    public function remove(int $id, Audit $audit): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $site = Site::query()->forOrganization($this->organization())->findOrFail($id);
        $audit->record('website.removed', $site, old: ['host' => $site->host], label: $site->host);
        $site->delete();
        $this->open = '';
        $this->dispatch('toast', type: 'success', message: 'Website removed.');
    }

    public function saveFeatures(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('integrations.manage', $organization);
        $widget = ChatWidget::for($organization);

        $features = [];
        foreach (array_keys(ChatWidget::FEATURES) as $key) {
            $features[$key] = (bool) ($this->features[$key] ?? false);
        }
        $widget->fill(['features' => $features, 'bookings_need_confirmation' => $this->needsConfirmation])->save();
        $audit->changes('chat_widget.updated', $widget, ['features', 'bookings_need_confirmation']);

        $this->dispatch('toast', type: 'success', message: array_filter($features)
            ? 'Saved. Your snippet shows '.implode(', ', array_map(fn ($k) => mb_strtolower(ChatWidget::FEATURES[$k]), array_keys(array_filter($features)))).'.'
            : 'Saved. Your snippet shows nothing until you switch something on.');
    }

    private function site(string $ulid): Site
    {
        return Site::query()->forOrganization($this->organization())->where('ulid', $ulid)->firstOrFail();
    }

    /** Verifying and checking reach out to the internet: a few tries per site every few minutes. */
    private function tooSoon(string $key, int $perTenMinutes): bool
    {
        $key = 'website:'.$key;
        if (RateLimiter::tooManyAttempts($key, $perTenMinutes)) {
            $this->dispatch('toast', type: 'warning', message: 'Please wait a few minutes before trying again.');

            return true;
        }
        RateLimiter::hit($key, 600);

        return false;
    }

    public function render(): View
    {
        $organization = $this->organization();
        $widget = ChatWidget::for($organization);

        return view('livewire.client.website', [
            'sites' => Site::query()->forOrganization($organization)->orderBy('created_at')->get(),
            'widget' => $widget,
            'featureLabels' => ChatWidget::FEATURES,
            'canManage' => auth()->user()->can('integrations.manage', $organization),
            'timezone' => $organization->timezoneOrDefault(),
        ]);
    }
}
