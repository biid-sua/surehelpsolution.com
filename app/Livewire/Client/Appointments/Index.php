<?php

namespace App\Livewire\Client\Appointments;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Appointments\ChangeAppointmentStatus;
use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Customers\MatchOrCreateCustomer;
use App\Enums\AppointmentStatus;
use App\Exceptions\SlotUnavailable;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\Customer;
use App\Services\Scheduling\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Appointments (spec §16): book with live availability (§19), never double-booked (§88),
 * then confirm, move, complete, mark no-show or cancel.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Appointments')]
class Index extends Component
{
    use ScopedToOrganization;
    use WithPagination;

    public const VIEWS = ['upcoming' => 'Upcoming', 'unconfirmed' => 'Needs confirming', 'past' => 'Past', 'cancelled' => 'Cancelled'];

    #[Url(except: 'upcoming')]
    public string $view = 'upcoming';

    /** List filters: text (title or customer), dates (local, by start) and service. */
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(as: 'service', except: '')]
    public string $serviceFilter = '';

    /** Opened from a notification, the calendar or a customer: ?appointment=<ulid>. */
    #[Url(as: 'appointment', except: '')]
    public string $selected = '';

    public bool $booking = false;

    /** @var array<string, string> */
    public array $form = [];

    public string $customerSearch = '';

    /** ULID of the appointment being moved. */
    public ?string $rescheduling = null;

    public string $cancelReason = '';

    /** @var list<string> suggested local start times "Y-m-d H:i" after a clash */
    public array $suggestions = [];

    public function mount(): void
    {
        $organization = $this->organization();
        $this->authorize('appointments.view', $organization);
        $this->view = array_key_exists($this->view, self::VIEWS) ? $this->view : 'upcoming';

        if ($this->selected !== '' && ! $this->find($this->selected, false)) {
            $this->selected = '';
        }

        // "Book" from a customer's page: /app/appointments?book=1&customer=<ulid>
        if (request()->boolean('book') && auth()->user()->can('appointments.create', $organization)) {
            $this->book((string) request()->query('customer', ''));
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['view', 'search', 'from', 'to', 'serviceFilter'], true)) {
            $this->resetPage();
        }
        if ($property === 'form.service_id') {
            $service = $this->service();
            $this->form['duration_minutes'] = (string) ($service->duration_minutes ?? BookAppointment::DEFAULT_DURATION);
            if ($service?->location_id) {
                $this->form['location_id'] = (string) $service->location_id;
            }
        }
        if (in_array($property, ['form.service_id', 'form.date', 'form.location_id', 'form.duration_minutes'], true)) {
            $this->form['time'] = '';
            $this->suggestions = [];
        }
    }

    public function book(string $customerUlid = ''): void
    {
        $organization = $this->organization();
        $this->authorize('appointments.create', $organization);
        $this->resetValidation();
        $this->suggestions = [];
        $this->customerSearch = '';
        $this->rescheduling = null;

        $customer = $customerUlid !== '' ? Customer::query()->forOrganization($organization)->where('ulid', $customerUlid)->first() : null;
        $services = $this->bookableServices();

        $this->form = [
            'customer_id' => (string) ($customer->id ?? ''),
            'new_name' => '', 'new_phone' => '',
            'service_id' => (string) ($services->count() === 1 ? $services->first()->id : ''),
            'location_id' => '',
            'date' => now($organization->timezoneOrDefault())->toDateString(),
            'time' => '',
            'duration_minutes' => (string) ($services->count() === 1 ? $services->first()->duration_minutes : BookAppointment::DEFAULT_DURATION),
            'status' => AppointmentStatus::Confirmed->value,
            'notes' => '', 'address' => '',
        ];
        $this->booking = true;
    }

    public function pickCustomer(int $id): void
    {
        $customer = Customer::query()->forOrganization($this->organization())->whereKey($id)->firstOrFail();
        $this->form['customer_id'] = (string) $customer->id;
        $this->customerSearch = '';
    }

    public function clearCustomer(): void
    {
        $this->form['customer_id'] = '';
    }

    public function pickTime(string $localDateTime): void
    {
        [$this->form['date'], $this->form['time']] = explode(' ', $localDateTime) + [1 => ''];
        $this->suggestions = [];
        $this->resetValidation('form.time');
    }

    public function save(BookAppointment $book, RescheduleAppointment $reschedule, MatchOrCreateCustomer $match): void
    {
        $organization = $this->organization();
        $moving = $this->rescheduling !== null;
        $this->authorize($moving ? 'appointments.update' : 'appointments.create', $organization);

        $this->validate([
            'form.date' => ['required', 'date_format:Y-m-d'],
            'form.time' => ['required', 'date_format:H:i'],
            'form.duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ] + ($moving ? [] : [
            'form.service_id' => ['nullable', 'integer'],
            'form.location_id' => ['nullable', 'integer'],
            'form.status' => ['required', 'in:confirmed,pending,tentative'],
            'form.notes' => ['nullable', 'string', 'max:5000'],
            'form.address' => ['nullable', 'string', 'max:255'],
            'form.new_name' => ['nullable', 'string', 'max:200'],
            'form.new_phone' => ['nullable', 'string', 'max:40'],
        ]), ['form.time.required' => 'Pick a time.'], ['form.date' => 'date', 'form.time' => 'time', 'form.duration_minutes' => 'length']);

        $start = CarbonImmutable::parse($this->form['date'].' '.$this->form['time'], $organization->timezoneOrDefault());
        $this->suggestions = [];

        try {
            if ($moving) {
                $appointment = $reschedule->handle($this->find((string) $this->rescheduling), $start, (int) $this->form['duration_minutes'], auth()->user());
                $message = 'Moved to '.$appointment->whenLabel().'.';
            } else {
                $customerId = $this->form['customer_id'] !== '' ? (int) $this->form['customer_id'] : null;
                if ($customerId === null && (filled($this->form['new_name']) || filled($this->form['new_phone']))) {
                    $customerId = $match->handle($organization, ['name' => $this->form['new_name'], 'phone' => $this->form['new_phone']], 'manual', auth()->id())['customer']->id ?? null;
                }

                $appointment = $book->handle($organization, [
                    'starts_at' => $start,
                    'duration_minutes' => (int) $this->form['duration_minutes'],
                    'service_id' => $this->form['service_id'] !== '' ? (int) $this->form['service_id'] : null,
                    'location_id' => $this->form['location_id'] !== '' ? (int) $this->form['location_id'] : null,
                    'customer_id' => $customerId,
                    'status' => $this->form['status'],
                    'notes' => $this->form['notes'],
                    'address' => $this->form['address'],
                ], auth()->user(), 'portal');
                $message = 'Booked for '.$appointment->whenLabel().'.';
            }
        } catch (SlotUnavailable $e) {
            $this->addError('form.time', $e->describe().($e->suggestions ? ' Free nearby:' : ''));
            $this->suggestions = array_map(fn (CarbonImmutable $s) => $s->format('Y-m-d H:i'), $e->suggestions);

            return;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('form.'.($field === 'starts_at' ? 'time' : $field), $messages[0]);
            }

            return;
        }

        $this->booking = false;
        $this->rescheduling = null;
        $this->selected = $appointment->ulid;
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function startReschedule(string $ulid): void
    {
        $this->authorize('appointments.update', $this->organization());
        $appointment = $this->find($ulid);
        $start = $appointment->localStart();

        $this->resetValidation();
        $this->suggestions = [];
        $this->rescheduling = $appointment->ulid;
        $this->form = [
            'service_id' => (string) ($appointment->service_id ?? ''),
            'location_id' => (string) ($appointment->location_id ?? ''),
            'date' => $start->toDateString(),
            'time' => $start->format('H:i'),
            'duration_minutes' => (string) $appointment->durationMinutes(),
        ];
        $this->booking = true;
    }

    public function setStatus(string $ulid, string $status, ChangeAppointmentStatus $change): void
    {
        $organization = $this->organization();
        $target = AppointmentStatus::tryFrom($status) ?? abort(422);
        $this->authorize($target === AppointmentStatus::Cancelled ? 'appointments.cancel' : 'appointments.update', $organization);

        try {
            $appointment = $change->handle($this->find($ulid), $target, auth()->user(), $target === AppointmentStatus::Cancelled ? $this->cancelReason : null);
            $this->cancelReason = '';
            $this->dispatch('toast', type: 'success', message: $appointment->title.': '.mb_strtolower($target->label()).'.');
        } catch (SlotUnavailable $e) {
            $this->dispatch('toast', type: 'error', message: $e->describe().' Move it to a new time instead.');
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());
        }
    }

    /** @return array{search: string, from: string, to: string, service: string} */
    public function filters(): array
    {
        return ['search' => $this->search, 'from' => $this->from, 'to' => $this->to, 'service' => $this->serviceFilter];
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'from', 'to', 'serviceFilter');
        $this->resetPage();
    }

    public function close(): void
    {
        $this->booking = false;
        $this->rescheduling = null;
    }

    private function find(string $ulid, bool $fail = true): ?Appointment
    {
        $query = Appointment::query()->forOrganization($this->organization())->where('ulid', $ulid);

        return $fail ? $query->firstOrFail() : $query->first();
    }

    private function service(): ?BusinessService
    {
        $id = $this->form['service_id'] ?? '';

        return $id !== '' ? BusinessService::query()->forOrganization($this->organization())->whereKey((int) $id)->first() : null;
    }

    /** @return Collection<int, BusinessService> */
    private function bookableServices(): Collection
    {
        return BusinessService::query()->forOrganization($this->organization())
            ->where('is_active', true)->where('is_bookable', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'duration_minutes', 'buffer_minutes', 'location_id']);
    }

    /**
     * Free start times for the form's date, service and location (empty without opening hours).
     *
     * @return list<CarbonImmutable>
     */
    private function slots(Availability $availability): array
    {
        if (! $this->booking || ($this->form['date'] ?? '') === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->form['date'])) {
            return [];
        }

        $service = $this->service();
        $ignore = $this->rescheduling ? $this->find($this->rescheduling, false)?->id : null;

        return $availability->slots(
            $this->organization(),
            $this->form['date'],
            max(5, (int) ($this->form['duration_minutes'] ?: BookAppointment::DEFAULT_DURATION)),
            (int) ($service->buffer_minutes ?? 0),
            ($this->form['location_id'] ?? '') !== '' ? (int) $this->form['location_id'] : null,
            $ignore,
            null,
            $service?->id,
        );
    }

    /**
     * One of the list's tabs, also used by the CSV export.
     *
     * @param  Builder<Appointment>  $query
     * @param  array{search?: string, from?: string, to?: string, service?: string}  $filters
     */
    public static function applyView(Builder $query, string $view, string $timezone, array $filters = []): void
    {
        $date = fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? CarbonImmutable::parse($d, $timezone) : null;
        $term = trim((string) ($filters['search'] ?? ''));
        $query
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s->where('title', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereHas('customer', fn (Builder $c) => $c->search($term))))
            ->when($date((string) ($filters['from'] ?? '')), fn (Builder $q, CarbonImmutable $d) => $q->where('starts_at', '>=', $d->startOfDay()->utc()))
            ->when($date((string) ($filters['to'] ?? '')), fn (Builder $q, CarbonImmutable $d) => $q->where('starts_at', '<=', $d->endOfDay()->utc()))
            ->when(ctype_digit((string) ($filters['service'] ?? '')), fn (Builder $q) => $q->where('service_id', (int) $filters['service']));

        match ($view) {
            'unconfirmed' => $query->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::Tentative->value])->where('ends_at', '>=', now())->orderBy('starts_at'),
            'past' => $query->where('starts_at', '<', now())->where('status', '!=', AppointmentStatus::Cancelled->value)->latest('starts_at'),
            'cancelled' => $query->where('status', AppointmentStatus::Cancelled->value)->latest('starts_at'),
            default => $query->blocking()->where('ends_at', '>=', now($timezone)->startOfDay()->utc())->orderBy('starts_at'),
        };
    }

    public function render(Availability $availability): View
    {
        $organization = $this->organization();
        $timezone = $organization->timezoneOrDefault();
        $user = auth()->user();

        $appointments = Appointment::query()->forOrganization($organization)
            ->with(['customer:id,ulid,first_name,last_name,company,phone,phone_e164', 'service:id,name', 'location:id,name'])
            ->tap(fn (Builder $q) => self::applyView($q, $this->view, $timezone, $this->filters()))
            ->paginate(25);

        $selectedAppointment = $this->selected !== ''
            ? Appointment::query()->forOrganization($organization)->with(['customer', 'service', 'location', 'call:id,call_id', 'bookedBy:id,name'])->where('ulid', $this->selected)->first()
            : null;

        $customerId = $this->form['customer_id'] ?? '';

        return view('livewire.client.appointments.index', [
            'appointments' => $appointments,
            'views' => self::VIEWS,
            'unconfirmedCount' => Appointment::query()->forOrganization($organization)
                ->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::Tentative->value])->where('ends_at', '>=', now())->count(),
            'selectedAppointment' => $selectedAppointment,
            'services' => $this->booking ? $this->bookableServices() : collect(),
            'locations' => $this->booking ? BusinessLocation::query()->forOrganization($organization)->orderByDesc('is_primary')->orderBy('name')->get(['id', 'name']) : collect(),
            'slots' => $this->slots($availability),
            'hasHours' => $organization->id && BusinessHour::query()->forOrganization($organization)->exists(),
            'pickedCustomer' => $customerId !== '' ? Customer::query()->forOrganization($organization)->find((int) $customerId) : null,
            'customerMatches' => $this->booking && mb_strlen(trim($this->customerSearch)) >= 2
                ? Customer::query()->forOrganization($organization)->search($this->customerSearch)->limit(6)->get()
                : collect(),
            'statuses' => [AppointmentStatus::Confirmed, AppointmentStatus::Pending, AppointmentStatus::Tentative],
            'can' => [
                'create' => $user->can('appointments.create', $organization),
                'update' => $user->can('appointments.update', $organization),
                'cancel' => $user->can('appointments.cancel', $organization),
            ],
            'timezone' => $timezone,
            'today' => now($timezone)->toDateString(),
            'filterServices' => BusinessService::query()->forOrganization($organization)->orderBy('name')->get(['id', 'name']),
            'filtered' => array_filter($this->filters()) !== [],
        ]);
    }
}
