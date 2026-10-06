<?php

namespace App\Livewire\Agent\Company;

use App\Livewire\Concerns\InAgentCompany;
use App\Models\Appointment;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One company's calendar for its agents (spec §20A): the next two weeks by day, in the company's timezone.
 * Booking itself happens in the workspace, under the company's rules.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Appointments')]
class Appointments extends Component
{
    use InAgentCompany;

    public int $offset = 0;

    public function mount(Organization $organization): void
    {
        $this->enterCompany($organization, 'appointments.view');
    }

    public function shift(int $weeks): void
    {
        $this->offset = max(-8, min(26, $this->offset + $weeks));
    }

    public function render(): View
    {
        $company = $this->company('appointments.view');
        $timezone = $company->timezoneOrDefault();
        $from = CarbonImmutable::now($timezone)->startOfDay()->addWeeks($this->offset);
        $to = $from->addDays(14);

        return view('livewire.agent.company.appointments', [
            'company' => $company,
            'days' => Appointment::query()->forOrganization($company)->whereBetween('starts_at', [$from->utc(), $to->utc()])
                ->with(['customer:id,ulid,first_name,last_name,company', 'service:id,name'])->orderBy('starts_at')->get()
                ->groupBy(fn (Appointment $a) => $a->starts_at->setTimezone($timezone)->format('Y-m-d')),
            'from' => $from,
            'to' => $to->subDay(),
            'timezone' => $timezone,
        ]);
    }
}
