<?php

namespace App\Livewire\Agent;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Calls\LogCall;
use App\Actions\Customers\MatchOrCreateCustomer;
use App\Actions\Tasks\CreateTask;
use App\Enums\AppointmentStatus;
use App\Enums\EscalationType;
use App\Enums\OutcomeCategory;
use App\Enums\TaskType;
use App\Exceptions\SlotUnavailable;
use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\Appointment;
use App\Models\BusinessProfile;
use App\Models\BusinessService;
use App\Models\CalendarConnection;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Escalation;
use App\Models\KnowledgeItem;
use App\Models\Organization;
use App\Services\Business\BusinessHours;
use App\Services\Calls\CallOutcomes;
use App\Services\Rules\BusinessRules;
use App\Services\Scheduling\Availability;
use App\Services\Training\TrainingReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Answering for one business (spec §15, §21): the business's briefing on the right, the call on the left.
 *
 * Call entry follows the spec's order: who is calling (matched to an existing customer by phone or
 * email, confirmed by the agent before anything is merged), why, what happened, an appointment if one
 * was booked (only times the business's hours and rules allow), notes, a follow-up, save. The call,
 * booking and follow-up are saved together or not at all.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
class Workspace extends Component
{
    use AgentWorkspaceOnly;

    #[Locked]
    public int $organizationId;

    /** @var array<string, mixed> */
    public array $entry = [];

    /** Customer the agent confirmed; null = not decided yet. */
    public ?int $customerId = null;

    /** The agent said the caller is a different person from the suggested match. */
    public bool $newCustomer = false;

    public string $customerSearch = '';

    public string $knowledgeSearch = '';

    /** @var list<string> */
    public array $suggestions = [];

    public ?string $lastSaved = null;

    public function mount(Organization $organization, TrainingReadiness $readiness): void
    {
        $this->authorizeFor($organization);
        if ($readiness->restriction(auth()->user(), $organization) === 'blocking') {
            // Only the company's training is open until it's done (D43).
            session()->flash('status', $readiness->message($organization, 'blocking'));
            $this->redirectRoute('agent.businesses.training', $organization->ulid);

            return;
        }
        $this->organizationId = $organization->id;
        $this->resetCall();
    }

    private function organization(): Organization
    {
        $organization = Organization::query()->findOrFail($this->organizationId);
        $this->authorizeFor($organization);
        $readiness = app(TrainingReadiness::class);
        abort_if($readiness->restriction(auth()->user(), $organization) === 'blocking', 403, $readiness->message($organization, 'blocking'));

        return $organization;
    }

    private function authorizeFor(Organization $organization): void
    {
        // 404, not 403: an agent can't tell an unassigned company from one that doesn't exist (D40).
        abort_unless(auth()->user()->hasPermissionIn('calls.create', $organization), 404);
    }

    private function resetCall(): void
    {
        $this->entry = [
            'phone' => '', 'name' => '', 'email' => '', 'address' => '',
            'reason' => '', 'outcome' => '', 'escalation_type' => EscalationType::UrgentIssue->value,
            'notes' => '',
            'book' => false, 'service_id' => '', 'date' => '', 'time' => '',
            'follow_up' => false, 'follow_up_title' => '', 'follow_up_date' => '',
        ];
        $this->customerId = null;
        $this->newCustomer = false;
        $this->customerSearch = '';
        $this->suggestions = [];
        $this->resetValidation();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['entry.phone', 'entry.email'], true)) {
            // A different number or email means a different match: ask again.
            $this->customerId = null;
            $this->newCustomer = false;
        }
        if (in_array($property, ['entry.service_id', 'entry.date'], true)) {
            $this->entry['time'] = '';
            $this->suggestions = [];
        }
        if ($property === 'entry.book' && $this->entry['book'] && $this->entry['date'] === '') {
            $this->entry['date'] = now($this->organization()->timezoneOrDefault())->toDateString();
        }
    }

    public function confirmCustomer(int $id): void
    {
        $customer = Customer::query()->forOrganization($this->organization())->whereKey($id)->firstOrFail();
        $this->customerId = $customer->id;
        $this->newCustomer = false;
        $this->customerSearch = '';
        // Fill blanks from what we know; never overwrite what the agent typed.
        $this->entry['name'] = $this->entry['name'] !== '' ? $this->entry['name'] : trim($customer->first_name.' '.$customer->last_name);
        $this->entry['phone'] = $this->entry['phone'] !== '' ? $this->entry['phone'] : (string) $customer->displayPhone();
        $this->entry['email'] = $this->entry['email'] !== '' ? $this->entry['email'] : (string) $customer->email;
        $this->entry['address'] = $this->entry['address'] !== '' ? $this->entry['address'] : (string) $customer->singleLineAddress();
    }

    public function differentPerson(): void
    {
        $this->customerId = null;
        $this->newCustomer = true;
    }

    public function pickTime(string $localDateTime): void
    {
        [$this->entry['date'], $this->entry['time']] = explode(' ', $localDateTime) + [1 => ''];
        $this->suggestions = [];
        $this->resetValidation('entry.time');
    }

    public function save(LogCall $logCall, BookAppointment $book, CreateTask $createTask, CallOutcomes $outcomes): void
    {
        $organization = $this->organization();
        $agent = auth()->user();
        $timezone = $organization->timezoneOrDefault();
        $readiness = app(TrainingReadiness::class);
        if ($level = $readiness->restriction($agent, $organization)) {
            $this->addError('training', $readiness->message($organization, $level));

            return;
        }

        $this->validate([
            'entry.phone' => ['nullable', 'string', 'max:20'],
            'entry.name' => ['nullable', 'string', 'max:255'],
            'entry.email' => ['nullable', 'email', 'max:255'],
            'entry.address' => ['nullable', 'string', 'max:255'],
            'entry.reason' => ['required', Rule::in(array_keys(CallLog::REASONS))],
            'entry.outcome' => ['required', 'string'],
            'entry.escalation_type' => ['nullable', Rule::enum(EscalationType::class)],
            'entry.notes' => ['nullable', 'string', 'max:5000'],
            'entry.service_id' => ['nullable', 'integer'],
            'entry.date' => ['exclude_unless:entry.book,true', 'required', 'date_format:Y-m-d'],
            'entry.time' => ['exclude_unless:entry.book,true', 'required', 'date_format:H:i'],
            'entry.follow_up_title' => ['exclude_unless:entry.follow_up,true', 'required', 'string', 'max:250'],
            'entry.follow_up_date' => ['exclude_unless:entry.follow_up,true', 'nullable', 'date_format:Y-m-d'],
        ], ['entry.time.required' => 'Pick a time.', 'entry.reason.required' => 'Why did they call?', 'entry.outcome.required' => 'How did the call end?'],
            ['entry.follow_up_title' => 'follow-up']);

        if (filled($this->entry['phone']) || filled($this->entry['email'])) {
            // A likely match the agent hasn't answered yet: ask before merging (spec §15).
            $match = app(MatchOrCreateCustomer::class)->lookup($organization, $this->entry['phone'], $this->entry['email']);
            if ($match && $this->customerId === null && ! $this->newCustomer) {
                $this->addError('customer', 'Is this '.$match['customer']->fullName().'? Confirm or choose "different person".');

                return;
            }
        }

        $category = $outcomes->category($organization, $this->entry['outcome']);
        $local = now($timezone);

        $booked = null;
        try {
            $call = DB::transaction(function () use ($logCall, $book, $createTask, $organization, $agent, $timezone, $category, $local, &$booked) {
                $call = $logCall->handle($agent, [
                    'call_date' => $local->toDateString(),
                    'call_time' => $local->format('H:i'),
                    'caller_name' => $this->entry['name'] ?: null,
                    'caller_phone' => $this->entry['phone'] ?: null,
                    'caller_email' => $this->entry['email'] ?: null,
                    'reason_for_call' => $this->entry['reason'],
                    'call_outcome' => $this->entry['outcome'],
                    'agent_name' => Str::before(trim($agent->name).' ', ' '),
                    'status' => $this->entry['book'] ? 'service-requested' : 'new',
                    'service_request' => (bool) $this->entry['book'],
                    'service_location' => $this->entry['address'] ?: null,
                    'notes' => $this->entry['notes'] ?: null,
                    'escalation_type' => $category === OutcomeCategory::Escalated ? $this->entry['escalation_type'] : null,
                    'customer_id' => $this->customerId,
                    'new_customer' => $this->newCustomer,
                ], $organization);

                if ($this->entry['book']) {
                    $booked = $book->handle($organization, [
                        'starts_at' => CarbonImmutable::parse($this->entry['date'].' '.$this->entry['time'], $timezone),
                        'service_id' => $this->entry['service_id'] !== '' ? (int) $this->entry['service_id'] : null,
                        'customer_id' => $call->customer_id,
                        'call_log_id' => $call->id,
                        'address' => $this->entry['address'] ?: null,
                        'notes' => $this->entry['notes'] ?: null,
                    ], $agent, 'agent', strict: true);
                }

                if ($this->entry['follow_up']) {
                    $createTask->handle($organization, [
                        'type' => TaskType::FollowUp,
                        'title' => $this->entry['follow_up_title'],
                        'description' => $this->entry['notes'] ?: null,
                        'priority' => 'normal',
                        'due_at' => $this->entry['follow_up_date'] !== ''
                            ? CarbonImmutable::parse($this->entry['follow_up_date'].' 17:00', $timezone)->utc()
                            : null,
                        'customer_id' => $call->customer_id,
                        'call_log_id' => $call->id,
                    ], $agent, 'call');
                }

                return $call;
            });
        } catch (SlotUnavailable $e) {
            $this->addError('entry.time', $e->describe().' Nothing was saved yet. Pick another time.');
            $this->suggestions = array_map(fn (CarbonImmutable $s) => $s->format('Y-m-d H:i'), $e->suggestions);

            return;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $target = match ($field) {
                    'starts_at' => 'entry.time',
                    'address' => 'entry.address',
                    'client_id' => 'customer',
                    'call_outcome' => 'entry.outcome',
                    default => 'entry.'.$field,
                };
                $this->addError($target, $messages[0].' Nothing was saved yet.');
            }

            return;
        }

        $this->lastSaved = $call->call_id;
        $this->resetCall();
        $this->dispatch('toast', type: 'success', message: "Call {$call->call_id} saved. The business has been notified."
            .($booked?->status === AppointmentStatus::Pending ? ' The booking waits for the business to approve it.' : ''));
    }

    public function newCall(): void
    {
        $this->resetCall();
    }

    public function render(BusinessHours $hours, BusinessRules $rules, CallOutcomes $outcomes, Availability $availability): View
    {
        $organization = $this->organization();
        $timezone = $organization->timezoneOrDefault();
        $profile = BusinessProfile::query()->forOrganization($organization)->first();

        $match = null;
        if ($this->customerId === null && ! $this->newCustomer && (filled($this->entry['phone']) || filled($this->entry['email']))) {
            $match = app(MatchOrCreateCustomer::class)->lookup($organization, $this->entry['phone'], $this->entry['email']);
        }

        $historyFor = $this->customerId ? Customer::query()->forOrganization($organization)->find($this->customerId) : ($match['customer'] ?? null);

        $services = BusinessService::query()->forOrganization($organization)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get();
        $bookable = $services->where('is_bookable', true)->values();
        $service = $this->entry['service_id'] !== '' ? $bookable->firstWhere('id', (int) $this->entry['service_id']) : null;

        $slots = [];
        if ($this->entry['book'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->entry['date'])) {
            $slots = $availability->slots($organization, $this->entry['date'], (int) ($service->duration_minutes ?? BookAppointment::DEFAULT_DURATION),
                (int) ($service->buffer_minutes ?? 0), $service?->location_id, serviceId: $service?->id);
        }

        $knowledge = KnowledgeItem::query()->forOrganization($organization)->forAgents()
            ->search($this->knowledgeSearch)->ordered()->limit($this->knowledgeSearch !== '' ? 20 : 12)->get();

        return view('livewire.agent.workspace', [
            'organization' => $organization,
            'profile' => $profile,
            'status' => $hours->status($organization),
            'localNow' => now($timezone),
            'weekly' => $hours->weekly($organization),
            'briefing' => $rules->briefing($organization),
            'knowledge' => $knowledge,
            'services' => $services,
            'bookable' => $bookable,
            'slots' => $slots,
            'outcomeMenu' => $outcomes->menu($organization),
            'isEscalation' => $outcomes->category($organization, $this->entry['outcome'] ?: null) === OutcomeCategory::Escalated,
            'isCallback' => $outcomes->category($organization, $this->entry['outcome'] ?: null) === OutcomeCategory::Callback,
            'reasons' => CallLog::REASONS,
            'escalationTypes' => array_filter(EscalationType::cases(), fn ($t) => $t !== EscalationType::AiUncertainty),
            'match' => $match,
            'confirmed' => $this->customerId ? $historyFor : null,
            'customerMatches' => mb_strlen(trim($this->customerSearch)) >= 2
                ? Customer::query()->forOrganization($organization)->search($this->customerSearch)->limit(6)->get()
                : collect(),
            'history' => $historyFor ? [
                'calls' => CallLog::query()->forOrganization($organization)->where('customer_id', $historyFor->id)->latest('created_at')->limit(5)->get(),
                'appointments' => Appointment::query()->forOrganization($organization)->where('customer_id', $historyFor->id)->blocking()->where('ends_at', '>=', now())->orderBy('starts_at')->limit(3)->get(),
                'tasks' => $historyFor->tasks()->open()->limit(3)->get(),
            ] : null,
            'todaysAppointments' => Appointment::query()->forOrganization($organization)->blocking()
                ->whereBetween('starts_at', [now($timezone)->startOfDay()->utc(), now($timezone)->endOfDay()->utc()])->orderBy('starts_at')->get(),
            'activeEscalations' => Escalation::query()->forOrganization($organization)->active()->count(),
            'calendarBroken' => CalendarConnection::query()->forOrganization($organization)->where('status', CalendarConnection::STATUS_NEEDS_REAUTH)->exists(),
            'priceLabel' => fn (BusinessService $s) => $s->price_type->display($s->price_cents, $s->currency),
            'timezone' => $timezone,
        ])->title($organization->name.' · Agent workspace');
    }
}
