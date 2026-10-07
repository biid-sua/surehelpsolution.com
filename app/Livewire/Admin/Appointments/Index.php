<?php

namespace App\Livewire\Admin\Appointments;

use App\Enums\AppointmentStatus;
use App\Livewire\Admin\Concerns\FiltersPlatformRecords;
use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Appointment;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every appointment on the platform (spec §5 Super Admin "Appointments"). Without dates it shows what's
 * coming up first; dates filter by start time. Times are shown in each business's timezone.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Appointments')]
class Index extends Component
{
    use FiltersPlatformRecords;
    use PlatformAdminOnly;
    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('appointments.view');
    }

    public function render(): View
    {
        $dated = $this->from !== '' || $this->to !== '';
        $appointments = $this->applyCommonFilters(Appointment::withoutGlobalScopes(), 'appointments.starts_at')
            ->with(['organization:id,ulid,name,timezone', 'customer:id,first_name,last_name,company', 'service:id,name', 'bookedBy:id,name'])
            ->when(AppointmentStatus::tryFrom($this->status), fn (Builder $q, AppointmentStatus $s) => $q->where('status', $s->value))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s->where('title', 'like', $this->likeTerm())
                ->orWhereHas('customer', fn (Builder $c) => $c->withoutGlobalScopes()->search(trim($this->search)))))
            ->when(! $dated, fn (Builder $q) => $q->where('starts_at', '>=', now()->startOfDay())->orderBy('starts_at'))
            ->when($dated, fn (Builder $q) => $q->latest('starts_at'))
            ->paginate(30);

        return view('livewire.admin.records.appointments', [
            'appointments' => $appointments,
            'businesses' => $this->businessOptions(),
            'statuses' => AppointmentStatus::cases(),
            'dated' => $dated,
        ]);
    }
}
