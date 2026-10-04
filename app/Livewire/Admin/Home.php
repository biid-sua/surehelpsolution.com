<?php

namespace App\Livewire\Admin;

use App\Enums\CallOwnershipSource;
use App\Enums\OrganizationStatus;
use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Platform overview (spec §45): real counts only.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Overview')]
class Home extends Component
{
    use PlatformAdminOnly;

    public function mount(): void
    {
        $this->authorize('dashboard.view');
    }

    public function render(): View
    {
        $today = now()->startOfDay();

        $needsReview = CallLog::query()
            ->whereIn('ownership_source', [CallOwnershipSource::EmailMatch->value, CallOwnershipSource::Unassigned->value])
            ->count();

        return view('livewire.admin.home', [
            'stats' => [
                'organizations' => Organization::where('status', OrganizationStatus::Active)->count(),
                'newOrganizations' => Organization::where('created_at', '>=', now()->startOfMonth())->count(),
                'agents' => User::where('role', 'agent')->where('is_active', true)->count(),
                'callsToday' => CallLog::where('created_at', '>=', $today)->count(),
                'needsReview' => $needsReview,
            ],
            'recentCalls' => CallLog::with('organization:id,ulid,name')->latest('created_at')->latest('id')->limit(8)->get(),
            'busiest' => Organization::query()
                ->withCount(['callLogs as calls_30d' => fn ($q) => $q->where('created_at', '>=', now()->subDays(30))])
                ->orderByDesc('calls_30d')
                ->limit(5)
                ->get(),
        ]);
    }
}
