<?php

namespace App\Livewire\Client;

use App\Actions\Billing\ManageAddons;
use App\Jobs\PullSearchConsole;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Addon;
use App\Models\BusinessService;
use App\Models\SearchConsoleConnection;
use App\Models\SeoTask;
use App\Services\Billing\AiCredits;
use App\Services\Billing\FeatureAccess;
use App\Services\Seo\SearchConsole;
use App\Services\Seo\SeoDesk;
use App\Services\Seo\SeoStats;
use App\Services\Seo\SeoTaskCatalog;
use App\Services\Seo\SeoTokens;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * SEO (D57): the business takes our SEO service; AI does the SEO work with its monthly token
 * allowance; the statistics show the difference before and after.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('SEO')]
class Seo extends Component
{
    use ScopedToOrganization;

    public const TABS = ['overview' => 'Results', 'work' => 'SEO work', 'settings' => 'Settings'];

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    #[Url(as: 'task', except: '')]
    public string $selected = '';

    /** @var array{focus: string, service: string, area: string, topic: string} */
    public array $input = ['focus' => '', 'service' => '', 'area' => '', 'topic' => ''];

    public bool $autopilot = true;

    public string $areas = '';

    public string $keywords = '';

    public function mount(): void
    {
        $organization = $this->organization();
        $this->authorize('seo.view', $organization);
        $this->tab = array_key_exists($this->tab, self::TABS) ? $this->tab : 'overview';
        $settings = $organization->seo_settings ?? [];
        $this->autopilot = (bool) ($settings['autopilot'] ?? true);
        $this->areas = implode(', ', (array) ($settings['areas'] ?? []));
        $this->keywords = implode(', ', (array) ($settings['keywords'] ?? []));
    }

    /** Take the SEO service: switch on the add-on (it's added to the next invoice). */
    public function turnOn(ManageAddons $addons): void
    {
        $organization = $this->organization();
        $this->authorize('billing.manage', $organization);
        $addon = Addon::query()->where('slug', 'seo_service')->where('is_active', true)->firstOrFail();

        try {
            $addons->activate($organization, $addon, auth()->user());
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'warning', message: (string) collect($e->errors())->flatten()->first());

            return;
        }
        app(FeatureAccess::class)->forget($organization);
        $this->dispatch('toast', type: 'success', message: 'The SEO service is on. Your first keyword plan is being written now.');
        rescue(fn () => app(SeoDesk::class)->request($organization, 'keyword_plan', [], auth()->user(), 'autopilot'), report: false);
        $this->tab = 'work';
    }

    public function run(string $type, SeoDesk $desk): void
    {
        $organization = $this->organization();
        $this->authorize('seo.manage', $organization);
        $rules = [
            'keyword_plan' => ['input.focus' => ['nullable', 'string', 'max:200']],
            'local_page' => ['input.service' => ['required', 'string', 'max:120'], 'input.area' => ['required', 'string', 'max:120']],
            'faq' => ['input.topic' => ['nullable', 'string', 'max:200']],
        ][$type] ?? [];
        $this->validate($rules, attributes: ['input.service' => 'service', 'input.area' => 'town or area']);

        $input = array_filter(match ($type) {
            'keyword_plan' => ['focus' => trim($this->input['focus'])],
            'local_page' => ['service' => trim($this->input['service']), 'area' => trim($this->input['area'])],
            'faq' => ['topic' => trim($this->input['topic'])],
            default => [],
        });

        try {
            $task = $desk->request($organization, $type, $input, auth()->user());
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'warning', message: (string) collect($e->errors())->flatten()->first());

            return;
        }
        $this->reset('input');
        $this->selected = $task->ulid;
        $this->dispatch('toast', type: 'success', message: SeoTaskCatalog::TYPES[$type]['label'].' started. It usually takes under a minute.');
    }

    public function review(string $decision, Audit $audit): void
    {
        $this->authorize('seo.manage', $this->organization());
        abort_unless(in_array($decision, ['approved', 'dismissed'], true), 422);
        $task = $this->task();
        abort_unless($task->status === 'done', 422);
        $task->update(['review' => $decision]);
        $audit->changes('seo.task_reviewed', $task, ['review']);
        $this->dispatch('toast', type: 'success', message: $decision === 'approved'
            ? 'Approved. Our team will put it on your site, or you can do it yourself and mark it live.'
            : 'Set aside.');
    }

    public function markLive(Audit $audit): void
    {
        $this->authorize('seo.manage', $this->organization());
        $task = $this->task();
        abort_unless($task->status === 'done', 422);
        $task->update(['applied_at' => $task->applied_at ? null : now(), 'applied_by_user_id' => $task->applied_at ? null : auth()->id(), 'review' => 'approved']);
        $audit->changes('seo.task_applied', $task, ['applied_at']);
    }

    public function saveSettings(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('seo.manage', $organization);
        $this->validate(['areas' => ['nullable', 'string', 'max:500'], 'keywords' => ['nullable', 'string', 'max:1000']]);
        $split = fn (string $s) => array_slice(array_values(array_filter(array_map(fn ($v) => mb_substr(trim($v), 0, 80), explode(',', $s)))), 0, 20);

        $organization->forceFill(['seo_settings' => ['autopilot' => $this->autopilot, 'areas' => $split($this->areas), 'keywords' => $split($this->keywords)]])->save();
        $audit->changes('organization.updated', $organization, ['seo_settings']);
        $this->dispatch('toast', type: 'success', message: 'Saved.');
    }

    public function chooseSite(string $url, Audit $audit): void
    {
        $this->authorize('seo.manage', $this->organization());
        $connection = SearchConsoleConnection::query()->forOrganization($this->organization())->firstOrFail();
        abort_unless(in_array($url, array_column($connection->sites ?? [], 'url'), true), 422);
        $connection->update(['site_url' => $url, 'last_synced_at' => null]);
        $audit->changes('seo.search_console_site', $connection, ['site_url']);
        Bus::dispatchAfterResponse(new PullSearchConsole($connection->id));
        $this->dispatch('toast', type: 'success', message: 'Fetching statistics for '.$url.'.');
    }

    private function task(): SeoTask
    {
        return SeoTask::query()->forOrganization($this->organization())->where('ulid', $this->selected)->firstOrFail();
    }

    public function render(FeatureAccess $features, SeoTokens $tokens, SeoStats $stats, SeoDesk $desk, SearchConsole $console): View
    {
        $organization = $this->organization();
        $user = auth()->user();
        $on = $features->allows($organization, 'seo_service');
        $tasks = SeoTask::query()->forOrganization($organization)->latest('id')->limit(30)->get();
        $current = $this->selected !== '' ? $tasks->firstWhere('ulid', $this->selected) ?? SeoTask::query()->forOrganization($organization)->where('ulid', $this->selected)->first() : null;

        return view('livewire.client.seo', [
            'organization' => $organization,
            'on' => $on,
            'addon' => $on ? null : Addon::query()->where('slug', 'seo_service')->where('is_active', true)->first(),
            'stats' => $on || $tasks->isNotEmpty() ? $stats->for($organization) : null,
            'tasks' => $tasks,
            'current' => $current,
            'busy' => $tasks->contains(fn (SeoTask $t) => in_array($t->status, ['queued', 'running'], true)),
            'types' => SeoTaskCatalog::TYPES,
            'blockers' => $on ? collect(SeoTaskCatalog::TYPES)->map(fn ($d, string $type) => $desk->blocker($organization, $type))->all() : [],
            'tokens' => ['used' => $tokens->used($organization), 'allowance' => $tokens->allowance($organization), 'credits' => app(AiCredits::class)->balance($organization)],
            'connection' => SearchConsoleConnection::query()->forOrganization($organization)->first(),
            'consoleAvailable' => $console->isConfigured(),
            'services' => BusinessService::query()->forOrganization($organization)->where('is_active', true)->orderBy('name')->pluck('name'),
            'canManage' => $user->can('seo.manage', $organization),
            'canBill' => $user->can('billing.manage', $organization),
            'tabs' => self::TABS,
        ]);
    }
}
