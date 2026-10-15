<?php

namespace App\Livewire\Admin\Seo;

use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Organization;
use App\Models\SearchConsoleConnection;
use App\Models\SeoTask;
use App\Services\Billing\FeatureAccess;
use App\Services\Seo\SeoDesk;
use App\Services\Seo\SeoTaskCatalog;
use App\Services\Seo\SeoTokens;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * SEO service for staff (D57): approved work waiting to be put on businesses' sites, every business
 * using the service with its AI token use this month, a per-business allowance, and running tasks for them.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('SEO service')]
class Index extends Component
{
    use PlatformAdminOnly;

    #[Url(as: 'task', except: '')]
    public string $selected = '';

    /** @var array<int, string> organization id => allowance being edited */
    public array $allowance = [];

    public function mount(): void
    {
        $this->authorize('seo.manage');
    }

    public function markLive(Audit $audit): void
    {
        $this->authorize('seo.manage');
        $task = SeoTask::withoutGlobalScopes()->where('ulid', $this->selected)->where('status', 'done')->firstOrFail();
        $task->update(['applied_at' => now(), 'applied_by_user_id' => auth()->id(), 'review' => 'approved']);
        $audit->changes('seo.task_applied', $task, ['applied_at']);
        $this->dispatch('toast', type: 'success', message: 'Marked live. It now shows on the business\'s results charts.');
    }

    public function saveAllowance(int $organizationId, Audit $audit): void
    {
        $this->authorize('billing.manage');
        $value = trim((string) ($this->allowance[$organizationId] ?? ''));
        if ($value !== '' && (! ctype_digit($value) || (int) $value > 50_000_000)) {
            $this->addError("allowance.$organizationId", 'Whole tokens, up to 50,000,000.');

            return;
        }
        $org = Organization::query()->findOrFail($organizationId);
        $org->forceFill(['seo_token_allowance' => $value === '' ? null : (int) $value])->save();
        $audit->changes('organization.updated', $org, ['seo_token_allowance']);
        $this->dispatch('toast', type: 'success', message: $value === '' ? 'Back to the plan\'s allowance.' : 'Allowance saved.');
    }

    public function runFor(int $organizationId, string $type, SeoDesk $desk): void
    {
        $this->authorize('seo.manage');
        try {
            $task = $desk->request(Organization::query()->findOrFail($organizationId), $type, [], auth()->user(), 'staff');
            $this->selected = $task->ulid;
            $this->dispatch('toast', type: 'success', message: 'Started.');
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'warning', message: (string) collect($e->errors())->flatten()->first());
        }
    }

    public function render(FeatureAccess $features, SeoTokens $tokens): View
    {
        $withTasks = SeoTask::withoutGlobalScopes()->distinct()->pluck('organization_id');
        $businesses = Organization::query()->whereIn('status', ['active', 'onboarding'])->orderBy('name')->get()
            ->filter(fn (Organization $o) => $features->allows($o, 'seo_service') || $withTasks->contains($o->id))->values();
        foreach ($businesses as $b) {
            $this->allowance[$b->id] ??= $b->seo_token_allowance === null ? '' : (string) $b->seo_token_allowance;
        }

        return view('livewire.admin.seo.index', [
            'toApply' => SeoTask::withoutGlobalScopes()->with('organization:id,ulid,name')->where('status', 'done')->where('review', 'approved')
                ->whereNull('applied_at')->oldest('updated_at')->limit(50)->get(),
            'businesses' => $businesses->map(fn (Organization $o) => [
                'org' => $o,
                'on' => $features->allows($o, 'seo_service'),
                'used' => $tokens->used($o),
                'allowance' => $tokens->allowance($o),
                'console' => SearchConsoleConnection::withoutGlobalScopes()->where('organization_id', $o->id)->exists(),
                'last' => SeoTask::withoutGlobalScopes()->where('organization_id', $o->id)->latest('id')->first(),
            ]),
            'current' => $this->selected !== '' ? SeoTask::withoutGlobalScopes()->with('organization:id,ulid,name')->where('ulid', $this->selected)->first() : null,
            'types' => SeoTaskCatalog::TYPES,
        ]);
    }
}
