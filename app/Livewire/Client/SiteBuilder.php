<?php

namespace App\Livewire\Client;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Site;
use App\Models\SiteEdit;
use App\Models\SiteVersion;
use App\Services\Sites\SiteBuilder as Builder;
use App\Services\Sites\SiteContent;
use App\Services\Sites\SiteEditor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The business's hosted website (D99): start one from a template, ask for changes in plain words,
 * preview the draft, publish it, and go back to any earlier version.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Website builder')]
class SiteBuilder extends Component
{
    use ScopedToOrganization;

    public string $template = 'bold';

    public string $subdomain = '';

    public string $prompt = '';

    /** The page shown in the preview (slug; "" is the home page). */
    #[Url(except: '')]
    public string $page = '';

    public function mount(Builder $builder): void
    {
        $this->authorize('integrations.view', $this->organization());
        $this->subdomain = $builder->suggestSubdomain($this->organization());
    }

    public function create(Builder $builder): void
    {
        $organization = $this->organization();
        $this->authorize('integrations.manage', $organization);
        $this->resetValidation();

        try {
            $builder->create($organization, $this->template, $this->subdomain, auth()->user());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->dispatch('toast', type: 'success', message: 'Your website is ready to look over. Ask for any changes, then publish it.');
    }

    public function ask(SiteEditor $editor): void
    {
        $organization = $this->organization();
        $this->authorize('integrations.manage', $organization);
        $this->resetValidation();
        if (RateLimiter::tooManyAttempts('site-edit:'.$organization->id, 30)) {
            $this->addError('prompt', 'That\'s a lot of changes in a short time. Please wait a few minutes.');

            return;
        }
        RateLimiter::hit('site-edit:'.$organization->id, 3600);

        try {
            $editor->request($this->site(), $this->prompt, $this->page, auth()->user());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->prompt = '';
    }

    public function publish(Builder $builder): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $site = $this->site();
        $builder->publish($site, auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Published. Visitors now see the new version.');
    }

    public function unpublish(Builder $builder): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $builder->unpublish($this->site(), auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Your website is offline. Publish again any time.');
    }

    public function restore(int $number, Builder $builder): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $site = $this->site();
        $version = SiteVersion::query()->forOrganization($this->organization())->where('site_id', $site->id)->where('number', $number)->firstOrFail();
        $builder->restore($site, $version, auth()->user());
        $this->dispatch('toast', type: 'success', message: "Back to version {$number}. Publish it to make it live.");
    }

    /** Drop changes visitors haven't seen: the live version becomes the draft again. */
    public function discard(Builder $builder): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $site = $this->site();
        if ($site->published && $site->hasUnpublishedChanges()) {
            $builder->restore($site, $site->published, auth()->user());
            $this->dispatch('toast', type: 'success', message: 'Unpublished changes dropped.');
        }
    }

    private function site(): Site
    {
        return Site::query()->forOrganization($this->organization())->with('published')->firstOrFail();
    }

    public function render(SiteEditor $editor): View
    {
        $organization = $this->organization();
        $site = Site::query()->forOrganization($organization)->with('published')->first();
        $draft = $site?->draft();
        $pages = $draft ? SiteContent::normalize($draft->content)['pages'] : [];
        if ($draft && ! collect($pages)->contains('slug', $this->page)) {
            $this->page = '';
        }
        $edits = $site ? SiteEdit::query()->forOrganization($organization)->where('site_id', $site->id)->latest('id')->limit(8)->get() : collect();

        return view('livewire.client.site-builder', [
            'site' => $site,
            'draft' => $draft,
            'pages' => $pages,
            'templates' => config('sites.templates'),
            'domain' => config('sites.domain'),
            'edits' => $edits,
            'pending' => $edits->contains(fn (SiteEdit $e) => $e->isPending()),
            'versions' => $site ? SiteVersion::query()->forOrganization($organization)->where('site_id', $site->id)->with('author:id,name')->orderByDesc('number')->limit(12)->get() : collect(),
            'canManage' => auth()->user()->can('integrations.manage', $organization),
            'tokens' => ['used' => $editor->used($organization), 'allowance' => $editor->allowance($organization)],
        ]);
    }
}
