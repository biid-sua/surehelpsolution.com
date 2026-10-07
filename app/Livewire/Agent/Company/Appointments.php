<?php

namespace App\Livewire\Agent\Company;

use App\Actions\Appointments\ChangeAppointmentStatus;
use App\Actions\Appointments\RescheduleAppointment;
use App\Enums\AppointmentStatus;
use App\Exceptions\SlotUnavailable;
use App\Livewire\Concerns\InAgentCompany;
use App\Models\Appointment;
use App\Models\Organization;
use App\Services\Scheduling\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One company's calendar for its agents (spec §20A): the next two weeks by day, in the company's timezone.
 * Booking itself happens in the workspace, under the company's rules. Agents move an upcoming
 * appointment to a free time under the same strict rules, or cancel it with a reason (AGT-14).
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Appointments')]
class Appointments extends Component
{
    use InAgentCompany;

    public int $offset = 0;

    /** Id of the appointment being moved or cancelled; always looked up inside the company. */
    public ?int $moving = null;

    public ?int $cancelling = null;

    public string $moveDate = '';

    public string $moveTime = '';

    public string $cancelReason = '';

    public function mount(Organization $organization): void
    {
        $this->enterCompany($organization, 'appointments.view');
    }

    public function shift(int $weeks): void
    {
        $this->offset = max(-8, min(26, $this->offset + $weeks));
    }

    public function startMove(int $id): void
    {
        $appointment = $this->find($this->company('appointments.update'), $id);
        $this->close();
        $this->moving = $appointment->id;
        $this->moveDate = $appointment->localStart()->toDateString();
    }

    public function startCancel(int $id): void
    {
        $this->find($this->company('appointments.cancel'), $id);
        $this->close();
        $this->cancelling = $id;
    }

    public function close(): void
    {
        $this->reset('moving', 'cancelling', 'moveDate', 'moveTime', 'cancelReason');
        $this->resetValidation();
    }

    public function updatedMoveDate(): void
    {
        $this->moveTime = '';
    }

    public function move(RescheduleAppointment $reschedule): void
    {
        $company = $this->company('appointments.update');
        $this->validate(['moveDate' => ['required', 'date_format:Y-m-d'], 'moveTime' => ['required', 'date_format:H:i']],
            ['moveTime.required' => 'Pick a time.']);
        $appointment = $this->find($company, (int) $this->moving);

        try {
            // Strict: agents can't book outside hours or the business's rules (unlike owners).
            $appointment = $reschedule->handle($appointment, CarbonImmutable::parse($this->moveDate.' '.$this->moveTime, $company->timezoneOrDefault()), null, auth()->user(), true);
        } catch (SlotUnavailable $e) {
            $this->addError('moveTime', $e->describe());

            return;
        } catch (ValidationException $e) {
            $this->addError('moveTime', collect($e->errors())->flatten()->first());

            return;
        }

        $this->close();
        $this->dispatch('toast', type: 'success', message: 'Moved to '.$appointment->whenLabel().'.');
    }

    public function cancel(ChangeAppointmentStatus $change): void
    {
        $company = $this->company('appointments.cancel');
        $this->validate(['cancelReason' => ['required', 'string', 'max:250']], ['cancelReason.required' => 'Say why, for the business.']);

        try {
            $change->handle($this->find($company, (int) $this->cancelling), AppointmentStatus::Cancelled, auth()->user(), trim($this->cancelReason));
        } catch (ValidationException $e) {
            $this->addError('cancelReason', collect($e->errors())->flatten()->first());

            return;
        }

        $this->close();
        $this->dispatch('toast', type: 'success', message: 'Appointment cancelled.');
    }

    private function find(Organization $company, int $id): Appointment
    {
        return Appointment::query()->forOrganization($company)->whereKey($id)
            ->whereIn('status', AppointmentStatus::blockingValues())->where('starts_at', '>', now())
            ->firstOrFail();
    }

    /** @return list<CarbonImmutable> */
    private function slots(Organization $company, Availability $availability): array
    {
        $appointment = $this->moving ? Appointment::query()->forOrganization($company)->find($this->moving) : null;
        if (! $appointment || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->moveDate)) {
            return [];
        }

        return $availability->slots($company, $this->moveDate, $appointment->durationMinutes(),
            (int) $appointment->blocked_until->diffInMinutes($appointment->ends_at, true),
            $appointment->location_id, $appointment->id, null, $appointment->service_id);
    }

    public function render(Availability $availability): View
    {
        $company = $this->company('appointments.view');
        $timezone = $company->timezoneOrDefault();
        $from = CarbonImmutable::now($timezone)->startOfDay()->addWeeks($this->offset);
        $to = $from->addDays(14);
        $user = auth()->user();

        return view('livewire.agent.company.appointments', [
            'company' => $company,
            'days' => Appointment::query()->forOrganization($company)->whereBetween('starts_at', [$from->utc(), $to->utc()])
                ->with(['customer:id,ulid,first_name,last_name,company', 'service:id,name'])->orderBy('starts_at')->get()
                ->groupBy(fn (Appointment $a) => $a->starts_at->setTimezone($timezone)->format('Y-m-d')),
            'from' => $from,
            'to' => $to->subDay(),
            'timezone' => $timezone,
            'slots' => $this->slots($company, $availability),
            'can' => ['move' => $user->hasPermissionIn('appointments.update', $company), 'cancel' => $user->hasPermissionIn('appointments.cancel', $company)],
        ]);
    }
}
