<?php

namespace App\Http\Controllers\Api;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Appointments\ChangeAppointmentStatus;
use App\Actions\Appointments\RescheduleAppointment;
use App\Enums\AppointmentStatus;
use App\Exceptions\SlotUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\BusinessService;
use App\Models\Customer;
use App\Services\Scheduling\Availability;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Appointments and availability for the mobile app (docs/api.md, spec §16, §19, §88).
 */
class AppointmentController extends Controller
{
    private const RELATIONS = ['customer:id,ulid,first_name,last_name,company,phone,phone_e164', 'service:id,name', 'location:id,name', 'call:id,call_id', 'organization:id,timezone'];

    public function index(Request $request, CurrentOrganization $current): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::enum(AppointmentStatus::class)],
            'customer' => ['nullable', 'string', 'max:26'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $organization = $current->get();
        $timezone = $organization->timezoneOrDefault();
        $from = CarbonImmutable::parse($request->query('from', now($timezone)->toDateString()), $timezone)->startOfDay();

        $page = Appointment::query()->forOrganization($organization)
            ->with(self::RELATIONS)
            ->where('ends_at', '>=', $from->utc())
            ->when($request->query('to'), fn ($q, $to) => $q->where('starts_at', '<=', CarbonImmutable::parse($to, $timezone)->endOfDay()->utc()))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('customer'), fn ($q, $ulid) => $q->whereHas('customer', fn ($c) => $c->where('ulid', $ulid)))
            ->orderBy('starts_at')
            ->paginate((int) $request->query('per_page', 25));

        return ApiResponse::success(
            ['appointments' => AppointmentResource::collection($page->getCollection())->resolve($request)],
            meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        );
    }

    public function show(Request $request, CurrentOrganization $current, string $ulid): JsonResponse
    {
        return $this->respond($request, $this->find($current, $ulid));
    }

    /**
     * Free start times on a date (local to the business).
     */
    public function availability(Request $request, CurrentOrganization $current, Availability $availability): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'service_id' => ['nullable', 'integer'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $organization = $current->get();
        $service = isset($data['service_id']) ? BusinessService::query()->forOrganization($organization)->whereKey($data['service_id'])->firstOrFail() : null;
        $duration = (int) ($data['duration_minutes'] ?? $service->duration_minutes ?? BookAppointment::DEFAULT_DURATION);
        $slots = $availability->slots($organization, $data['date'], $duration, (int) ($service->buffer_minutes ?? 0), $data['location_id'] ?? $service?->location_id, serviceId: $service?->id);

        return ApiResponse::success([
            'date' => $data['date'],
            'timezone' => $organization->timezoneOrDefault(),
            'duration_minutes' => $duration,
            'slots' => array_map(fn (CarbonImmutable $s) => ['local' => $s->format('H:i'), 'starts_at' => $s->utc()->toIso8601String()], $slots),
        ]);
    }

    public function store(Request $request, CurrentOrganization $current, BookAppointment $book): JsonResponse
    {
        $organization = $current->get();
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'service_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'string', 'max:26'],
            'status' => ['nullable', Rule::in(['confirmed', 'pending', 'tentative'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $customerId = null;
        if (! empty($data['customer_id'])) {
            $customerId = Customer::query()->forOrganization($organization)->where('ulid', $data['customer_id'])->value('id')
                ?? throw ValidationException::withMessages(['customer_id' => ['Choose one of your customers.']]);
        }

        try {
            $appointment = $book->handle($organization, [
                'starts_at' => self::instant($data['starts_at'], $organization->timezoneOrDefault()),
                'customer_id' => $customerId,
            ] + array_intersect_key($data, array_flip(['duration_minutes', 'service_id', 'location_id', 'status', 'notes', 'address'])), $request->user(), 'api');
        } catch (SlotUnavailable $e) {
            return $this->conflict($e);
        }

        return $this->respond($request, $appointment, 'Appointment booked', 201);
    }

    public function update(Request $request, CurrentOrganization $current, string $ulid, RescheduleAppointment $reschedule, ChangeAppointmentStatus $change): JsonResponse
    {
        $appointment = $this->find($current, $ulid);
        $data = $request->validate([
            'starts_at' => ['sometimes', 'date'],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'status' => ['sometimes', Rule::enum(AppointmentStatus::class)],
            'cancellation_reason' => ['sometimes', 'nullable', 'string', 'max:250'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        $user = $request->user();
        $organization = $current->get();

        try {
            if (isset($data['starts_at']) || isset($data['duration_minutes'])) {
                abort_unless($user->can('appointments.update', $organization), 403);
                $start = isset($data['starts_at']) ? self::instant($data['starts_at'], $organization->timezoneOrDefault()) : $appointment->starts_at->toImmutable();
                $reschedule->handle($appointment, $start, $data['duration_minutes'] ?? null, $user);
            }
            if (array_key_exists('notes', $data)) {
                $appointment->forceFill(['notes' => $data['notes']])->save();
            }
            if (isset($data['status'])) {
                $status = AppointmentStatus::from($data['status']);
                abort_unless($user->can($status === AppointmentStatus::Cancelled ? 'appointments.cancel' : 'appointments.update', $organization), 403);
                $change->handle($appointment, $status, $user, $data['cancellation_reason'] ?? null);
            }
        } catch (SlotUnavailable $e) {
            return $this->conflict($e);
        }

        return $this->respond($request, $appointment->fresh(), 'Appointment updated');
    }

    /** ISO 8601 with an offset is an instant; without one it is the business's local time. */
    private static function instant(string $value, string $timezone): CarbonImmutable
    {
        return preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) ? CarbonImmutable::parse($value) : CarbonImmutable::parse($value, $timezone);
    }

    private function conflict(SlotUnavailable $e): JsonResponse
    {
        return ApiResponse::error($e->describe(), 409, ['starts_at' => [$e->describe()]], [
            'suggestions' => array_map(fn (CarbonImmutable $s) => ['local' => $s->format('Y-m-d\TH:i'), 'starts_at' => $s->utc()->toIso8601String()], $e->suggestions),
        ]);
    }

    private function find(CurrentOrganization $current, string $ulid): Appointment
    {
        return Appointment::query()->forOrganization($current->get())->where('ulid', $ulid)->firstOrFail();
    }

    private function respond(Request $request, Appointment $appointment, ?string $message = null, int $status = 200): JsonResponse
    {
        return ApiResponse::success(['appointment' => (new AppointmentResource($appointment->load(self::RELATIONS)))->resolve($request)], $message, $status);
    }
}
