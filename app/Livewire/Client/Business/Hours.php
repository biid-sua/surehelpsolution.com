<?php

namespace App\Livewire\Client\Business;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\BusinessHoliday;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Services\Business\BusinessHours;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Weekly hours with split shifts, emergency availability, temporary closure,
 * and holidays / special hours (spec §10).
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Business hours')]
class Hours extends Component
{
    use ScopedToOrganization;

    /** @var array<int, list<array<string, string>>> Carbon day (0 = Sunday) => intervals of ['opens' => 'H:i', 'closes' => 'H:i'] (browser-edited, so validated, not trusted) */
    public array $days = [];

    public bool $emergencyAvailable = false;

    public string $emergencyInstructions = '';

    public string $closedFrom = '';

    public string $closedUntil = '';

    public string $closureMessage = '';

    /** @var array{date: string, name: string, is_closed: bool, opens: string, closes: string} */
    public array $newHoliday = ['date' => '', 'name' => '', 'is_closed' => true, 'opens' => '', 'closes' => ''];

    public function mount(): void
    {
        $organization = $this->organization();
        $this->authorize('organization.view', $organization);

        foreach (array_keys(BusinessHours::DAYS) as $day) {
            $this->days[$day] = [];
        }

        BusinessHour::query()->forOrganization($organization)->whereNull('location_id')->orderBy('opens_at')->get()
            ->each(function (BusinessHour $hour) {
                $this->days[$hour->day_of_week][] = ['opens' => substr($hour->opens_at, 0, 5), 'closes' => substr($hour->closes_at, 0, 5)];
            });

        $profile = BusinessProfile::query()->forOrganization($organization)->first();
        $this->emergencyAvailable = (bool) $profile?->emergency_available;
        $this->emergencyInstructions = (string) $profile?->emergency_instructions;
        // Closures saved before vacation mode had a start date began straight away.
        $start = $profile === null ? null : ($profile->closed_from ?? ($profile->closed_until ? now($organization->timezoneOrDefault()) : null));
        $this->closedFrom = (string) $start?->toDateString();
        $this->closedUntil = (string) $profile?->closed_until?->toDateString();
        $this->closureMessage = (string) $profile?->closure_message;
    }

    public function addInterval(int $day): void
    {
        $last = end($this->days[$day]) ?: null;
        $this->days[$day][] = $last
            ? ['opens' => $last['closes'], 'closes' => $last['closes'] < '17:00' ? '17:00' : '20:00']
            : ['opens' => '09:00', 'closes' => '17:00'];
    }

    public function removeInterval(int $day, int $index): void
    {
        unset($this->days[$day][$index]);
        $this->days[$day] = array_values($this->days[$day]);
    }

    public function copyMondayToWeekdays(): void
    {
        foreach ([2, 3, 4, 5] as $day) {
            $this->days[$day] = $this->days[1];
        }
    }

    public function save(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);

        $this->validateIntervals();
        $this->validate([
            'emergencyInstructions' => ['nullable', 'string', 'max:2000'],
            'closedFrom' => ['nullable', 'date_format:Y-m-d', 'required_with:closedUntil'],
            'closedUntil' => ['nullable', 'date_format:Y-m-d', 'required_with:closedFrom', 'after_or_equal:closedFrom', 'after_or_equal:today'],
            'closureMessage' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($organization, $audit) {
            $before = BusinessHour::query()->forOrganization($organization)->whereNull('location_id')->count();
            BusinessHour::query()->forOrganization($organization)->whereNull('location_id')->delete();

            foreach ($this->days as $day => $intervals) {
                foreach ($intervals as $interval) {
                    BusinessHour::create([
                        'organization_id' => $organization->id,
                        'day_of_week' => $day,
                        'opens_at' => $interval['opens'],
                        'closes_at' => $interval['closes'],
                    ]);
                }
            }

            $profile = BusinessProfile::firstOrNew(['organization_id' => $organization->id]);
            $profile->fill([
                'emergency_available' => $this->emergencyAvailable,
                'emergency_instructions' => filled($this->emergencyInstructions) ? trim($this->emergencyInstructions) : null,
                'closed_from' => $this->closedFrom ?: null,
                'closed_until' => $this->closedUntil ?: null,
                'closure_message' => filled($this->closureMessage) ? trim($this->closureMessage) : null,
            ])->save();

            $audit->record('business_hours.updated', $organization, ['intervals' => $before], ['intervals' => collect($this->days)->flatten(1)->count()]);
            $audit->changes('business_profile.updated', $profile, ['emergency_available', 'emergency_instructions', 'closed_from', 'closed_until', 'closure_message']);
        });

        $this->dispatch('toast', type: 'success', message: 'Hours saved. Agents see the new schedule right away.');
    }

    public function addHoliday(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);

        // Radio inputs send "0"/"1" strings; normalise before deciding which fields are required.
        $closed = filter_var($this->newHoliday['is_closed'], FILTER_VALIDATE_BOOLEAN);
        $this->newHoliday['is_closed'] = $closed;
        $time = $closed ? ['nullable'] : ['required', 'date_format:H:i'];

        $this->validate([
            'newHoliday.date' => ['required', 'date_format:Y-m-d'],
            'newHoliday.name' => ['required', 'string', 'max:255'],
            'newHoliday.opens' => $time,
            'newHoliday.closes' => $closed ? ['nullable'] : [...$time, 'different:newHoliday.opens'],
        ], attributes: ['newHoliday.date' => 'date', 'newHoliday.name' => 'name', 'newHoliday.opens' => 'opening time', 'newHoliday.closes' => 'closing time']);

        $holiday = BusinessHoliday::updateOrCreate(
            ['organization_id' => $organization->id, 'date' => $this->newHoliday['date']],
            [
                'name' => trim($this->newHoliday['name']),
                'is_closed' => $closed,
                'opens_at' => $closed ? null : $this->newHoliday['opens'],
                'closes_at' => $closed ? null : $this->newHoliday['closes'],
            ],
        );
        $audit->record('business_holiday.saved', $holiday, new: ['date' => $this->newHoliday['date'], 'name' => $holiday->name, 'is_closed' => $holiday->is_closed]);

        $this->reset('newHoliday');
        $this->dispatch('toast', type: 'success', message: 'Date added.');
    }

    public function removeHoliday(int $id, Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);

        $holiday = BusinessHoliday::query()->forOrganization($organization)->findOrFail($id);
        $audit->record('business_holiday.removed', $holiday, old: ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);
        $holiday->delete();
    }

    /**
     * Each interval needs valid times; intervals on the same day must not overlap.
     */
    private function validateIntervals(): void
    {
        $errors = [];

        foreach ($this->days as $day => $intervals) {
            $sameDay = [];
            foreach ($intervals as $i => $interval) {
                $key = "days.$day.$i";
                $opens = (string) ($interval['opens'] ?? '');
                $closes = (string) ($interval['closes'] ?? '');

                if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $opens) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $closes)) {
                    $errors[$key] = 'Enter an opening and a closing time.';

                    continue;
                }
                if ($opens === $closes) {
                    $errors[$key] = 'Opening and closing time can\'t be the same.';

                    continue;
                }
                if ($closes > $opens) {
                    $sameDay[] = [$opens, $closes, $key];
                }
            }

            usort($sameDay, fn ($a, $b) => strcmp($a[0], $b[0]));
            for ($i = 1; $i < count($sameDay); $i++) {
                if ($sameDay[$i][0] < $sameDay[$i - 1][1]) {
                    $errors[$sameDay[$i][2]] = 'This overlaps with another shift on the same day.';
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function render(BusinessHours $engine): View
    {
        $organization = $this->organization();

        return view('livewire.client.business.hours', [
            'organization' => $organization,
            'dayNames' => BusinessHours::DAYS,
            'holidays' => BusinessHoliday::query()->forOrganization($organization)
                ->where('date', '>=', now($organization->timezoneOrDefault())->toDateString())
                ->orderBy('date')->get(),
            'status' => $engine->status($organization),
            'canEdit' => auth()->user()->can('organization.update', $organization),
        ]);
    }
}
