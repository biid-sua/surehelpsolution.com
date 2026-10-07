<?php

namespace App\Livewire\Client;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\BusinessProfile;
use App\Models\Escalation;
use App\Services\Business\BusinessHours;
use App\Services\Metrics\ClientMetrics;
use App\Services\Metrics\DashboardAlerts;
use App\Services\Setup\SetupProgress;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "What is happening in my business right now?" (spec §8.1)
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Dashboard')]
class Dashboard extends Component
{
    use ScopedToOrganization;

    #[Url(except: 'today')]
    public string $period = 'today';

    /** Custom range, local dates (Y-m-d). */
    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('dashboard.view', $this->organization());
        $this->normalizePeriod();
    }

    public function updatedPeriod(): void
    {
        $this->normalizePeriod();
        if ($this->period === 'custom' && $this->from === '') {
            $today = now($this->organization()->timezoneOrDefault())->toImmutable();
            $this->from = $today->subDays(29)->toDateString();
            $this->to = $today->toDateString();
        }
    }

    public function render(ClientMetrics $metrics, DashboardAlerts $alerts): View
    {
        $organization = $this->organization();
        [$from, $to] = $this->period === 'custom' ? [$this->from, $this->to] : [null, null];
        $customValid = $this->period !== 'custom' || ClientMetrics::customBounds($from, $to, $organization->timezoneOrDefault()) !== null;
        $hour = (int) now($organization->timezone ?: config('app.timezone'))->format('G');
        $partOfDay = $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening');

        return view('livewire.client.dashboard', [
            'organization' => $organization,
            'greeting' => 'Good '.$partOfDay.', '.Str::before(trim(auth()->user()->name).' ', ' '),
            'periods' => ClientMetrics::PERIODS,
            'kpis' => $metrics->kpis($organization, $this->period, $from, $to),
            'series' => $metrics->series($organization, $this->period, $from, $to),
            'hourly' => $metrics->range($organization, $this->period, $from, $to)['days'] === 1,
            'customValid' => $customValid,
            'maxDays' => ClientMetrics::MAX_CUSTOM_DAYS,
            'alerts' => $alerts->for($organization, auth()->user()),
            'activity' => auth()->user()->can('customers.view', $organization) ? $metrics->customerActivity($organization, $this->period, $from, $to) : null,
            'schedule' => $metrics->todaysSchedule($organization),
            'recentCalls' => $metrics->recentCalls($organization),
            'away' => (function () use ($organization) {
                $profile = BusinessProfile::query()->forOrganization($organization)->first();
                $today = now($organization->timezoneOrDefault())->toDateString();

                return $profile?->hasAwayAhead($today) ? ['now' => $profile->isAwayOn($today), 'profile' => $profile] : null;
            })(),
            'canManageHours' => auth()->user()->can('organization.update', $organization),
            'setup' => ! $organization->isSetUp() && auth()->user()->can('organization.update', $organization)
                ? app(SetupProgress::class)->count($organization) : null,
            'activeEscalations' => auth()->user()->can('escalations.view', $organization)
                ? Escalation::query()->forOrganization($organization)->active()->count()
                : 0,
        ]);
    }

    /** Back early: vacation mode ends today. */
    public function endVacation(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        $profile = BusinessProfile::query()->forOrganization($organization)->firstOrFail();
        $profile->forceFill(['closed_from' => null, 'closed_until' => null])->save();
        app(BusinessHours::class)->forget($organization);
        $audit->changes('business_profile.updated', $profile, ['closed_from', 'closed_until']);
        $this->dispatch('toast', type: 'success', message: 'Welcome back! Vacation mode is off and bookings are open again.');
    }

    private function normalizePeriod(): void
    {
        if (! array_key_exists($this->period, ClientMetrics::PERIODS)) {
            $this->period = 'today';
        }
    }
}
