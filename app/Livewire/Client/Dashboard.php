<?php

namespace App\Livewire\Client;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\BusinessProfile;
use App\Models\Escalation;
use App\Services\Billing\Trials;
use App\Services\Business\BusinessHours;
use App\Services\Metrics\ClientMetrics;
use App\Services\Metrics\DashboardAlerts;
use App\Services\Setup\SampleData;
use App\Services\Setup\SetupProgress;
use App\Support\Audit\Audit;
use App\Support\Calendar\CalendarSources;
use App\Support\Dashboard\DashboardLayout;
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

    /** The "Customise" panel is open (D77). */
    public bool $customising = false;

    public function mount(): void
    {
        $this->authorize('dashboard.view', $this->organization());
        $this->normalizePeriod();
        // A new trial's sample calls are from last week (D97): show them rather than an empty today.
        if (! request()->has('period') && app(SampleData::class)->has($this->organization())) {
            $today = now($this->organization()->timezoneOrDefault())->toImmutable();
            [$this->period, $this->from, $this->to] = ['custom', $today->subDays(13)->toDateString(), $today->toDateString()];
        }
    }

    /** Sample data from the free trial (D97): gone in one click, nothing real touched. */
    public function removeSampleData(SampleData $samples): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        $samples->remove($organization, auth()->user());
        $this->period = 'today';
        $this->dispatch('toast', type: 'success', message: 'Sample data removed. Everything you see now is your own.');
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

    public function render(ClientMetrics $metrics, DashboardAlerts $alerts, DashboardLayout $layout, CalendarSources $calendarSources): View
    {
        $organization = $this->organization();
        $user = auth()->user();
        [$from, $to] = $this->period === 'custom' ? [$this->from, $this->to] : [null, null];
        $customValid = $this->period !== 'custom' || ClientMetrics::customBounds($from, $to, $organization->timezoneOrDefault()) !== null;
        $hour = (int) now($organization->timezone ?: config('app.timezone'))->format('G');
        $partOfDay = $hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening');

        // Only what's on screen is worked out: hidden sections cost nothing.
        $sections = $layout->for($user, $organization);
        $shown = collect($sections)->where('visible', true)->keyBy('key');
        $kpis = $metrics->kpis($organization, $this->period, $from, $to); // the alerts read these too
        $calendar = null;
        if ($shown->has('calendar')) {
            $all = $calendarSources->for($organization);
            $external = collect($all)->where('kind', 'external');
            $calendar = [
                'sources' => $all,
                'anyConnected' => $external->contains('connected', true),
                'needsReconnect' => $external->where('status', 'needs_reauth')->pluck('label'),
            ];
        }

        return view('livewire.client.dashboard', [
            'organization' => $organization,
            'greeting' => 'Good '.$partOfDay.', '.Str::before(trim($user->name).' ', ' '),
            'periods' => ClientMetrics::PERIODS,
            'sections' => $sections,
            'placed' => DashboardLayout::place($sections),
            'usesPeriod' => $shown->contains('period', true),
            'kpis' => $kpis,
            'calendar' => $calendar,
            'series' => $shown->has('call_volume') ? $metrics->series($organization, $this->period, $from, $to) : null,
            'hourly' => $metrics->range($organization, $this->period, $from, $to)['days'] === 1,
            'customValid' => $customValid,
            'maxDays' => ClientMetrics::MAX_CUSTOM_DAYS,
            'alerts' => $alerts->for($organization, $user),
            'activity' => $shown->has('activity') ? $metrics->customerActivity($organization, $this->period, $from, $to) : null,
            'schedule' => $shown->has('schedule') ? $metrics->todaysSchedule($organization) : collect(),
            'recentCalls' => $shown->has('recent_calls') ? $metrics->recentCalls($organization) : collect(),
            'hasSamples' => app(SampleData::class)->has($organization),
            'canRemoveSamples' => auth()->user()->can('organization.update', $organization) && ! app(Trials::class)->ended($organization),
            'away' => (function () use ($organization) {
                $profile = BusinessProfile::query()->forOrganization($organization)->first();
                $today = now($organization->timezoneOrDefault())->toDateString();

                return $profile?->hasAwayAhead($today) ? ['now' => $profile->isAwayOn($today), 'profile' => $profile] : null;
            })(),
            'canManageHours' => $user->can('organization.update', $organization),
            'setup' => ! $organization->isSetUp() && $user->can('organization.update', $organization)
                ? app(SetupProgress::class)->count($organization) : null,
            'activeEscalations' => $user->can('escalations.view', $organization)
                ? Escalation::query()->forOrganization($organization)->active()->count()
                : 0,
        ]);
    }

    /** Show or hide a section of this person's dashboard (D77). */
    public function toggleSection(string $key, DashboardLayout $layout): void
    {
        abort_unless(isset(DashboardLayout::SECTIONS[$key]), 404);
        $layout->toggle(auth()->user(), $this->organization(), $key);
    }

    public function moveSection(string $key, int $direction, DashboardLayout $layout): void
    {
        abort_unless(isset(DashboardLayout::SECTIONS[$key]), 404);
        $layout->move(auth()->user(), $this->organization(), $key, $direction);
    }

    public function resetLayout(DashboardLayout $layout): void
    {
        $layout->reset(auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Your dashboard is back to the standard layout.');
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
