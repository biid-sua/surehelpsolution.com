<?php

namespace App\Services\Ai\Assistant;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Customers\MatchOrCreateCustomer;
use App\Actions\Escalations\RaiseEscalation;
use App\Actions\Tasks\CreateTask;
use App\Enums\AppointmentStatus;
use App\Enums\EscalationPriority;
use App\Enums\EscalationType;
use App\Enums\TaskType;
use App\Exceptions\SlotUnavailable;
use App\Models\AiAssistant;
use App\Models\BusinessService;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\KnowledgeItem;
use App\Services\Scheduling\Availability;
use App\Services\Tasks\CallbackDueTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only way the assistant acts (spec §33: "never allow an AI model to directly manipulate the
 * database"). Every tool runs the same application actions a human agent uses, for this one business
 * and this one conversation's customer. Results are short sentences for the model; nothing returned
 * reveals other customers' data.
 */
class AssistantTools
{
    /** Tools that change something: only offered in auto mode. */
    public const ACTIONS = ['book_appointment', 'save_customer_details', 'create_follow_up', 'hand_over_to_team'];

    public function __construct(
        private readonly Availability $availability,
        private readonly BookAppointment $book,
        private readonly MatchOrCreateCustomer $customers,
        private readonly CreateTask $tasks,
        private readonly RaiseEscalation $escalations,
        private readonly CallbackDueTime $callbackDue,
    ) {}

    /**
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function definitions(AiAssistant $assistant, bool $canAct): array
    {
        $tools = [
            [
                'name' => 'get_available_times',
                'description' => 'Free appointment start times, in the business\'s timezone. Give a date to see that day, or leave it out for the next openings. Use the service id from the services list when the customer wants a specific service.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD in the business\'s timezone'],
                    'service_id' => ['type' => 'integer'],
                ], 'additionalProperties' => false],
            ],
            [
                'name' => 'search_knowledge',
                'description' => 'Search the business\'s knowledge base (FAQs, policies, procedures) for anything not already in your instructions.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string'],
                ], 'required' => ['query'], 'additionalProperties' => false],
            ],
        ];

        if (! $canAct) {
            return $tools;
        }

        if ($assistant->can_book) {
            $tools[] = [
                'name' => 'book_appointment',
                'description' => 'Book an appointment once the customer has confirmed the service, the exact time (one returned by get_available_times) and their name plus a phone number or email.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'starts_at' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM in the business\'s timezone'],
                    'service_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'phone' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'address' => ['type' => 'string', 'description' => 'Where the work happens, when the service needs it'],
                    'notes' => ['type' => 'string', 'description' => 'What the customer needs, in a sentence or two'],
                ], 'required' => ['starts_at', 'name'], 'additionalProperties' => false],
            ];
        }

        $tools[] = [
            'name' => 'save_customer_details',
            'description' => 'Save the contact details the customer gave you (name, phone, email, address) so the team can follow up.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'email' => ['type' => 'string'], 'address' => ['type' => 'string'],
            ], 'additionalProperties' => false],
        ];
        $tools[] = [
            'name' => 'create_follow_up',
            'description' => 'Ask the team to call the customer back or follow up (for example for a quote). Use after saving their contact details.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'summary' => ['type' => 'string', 'description' => 'What the team should do and why'],
                'call_back' => ['type' => 'boolean', 'description' => 'True when the customer wants a phone call'],
            ], 'required' => ['summary'], 'additionalProperties' => false],
        ];
        $tools[] = [
            'name' => 'hand_over_to_team',
            'description' => 'Pass the conversation to a person and stop replying. Use for complaints, refunds, price exceptions, emergencies, sensitive topics, when the customer asks for a person, or when you are not sure.',
            'input_schema' => ['type' => 'object', 'properties' => [
                'reason' => ['type' => 'string', 'description' => 'One sentence for the team'],
                'category' => ['type' => 'string', 'enum' => ['customer_asked', 'complaint', 'refund_request', 'pricing_approval', 'emergency', 'urgent_issue', 'unsure']],
            ], 'required' => ['reason', 'category'], 'additionalProperties' => false],
        ];

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, content: string, summary: string}
     */
    public function run(string $name, array $input, Conversation $conversation, AiAssistant $assistant, ToolOutcome $outcome): array
    {
        try {
            return match ($name) {
                'get_available_times' => $this->availableTimes($conversation, $input),
                'search_knowledge' => $this->searchKnowledge($conversation, (string) ($input['query'] ?? '')),
                'book_appointment' => $assistant->can_book ? $this->bookAppointment($conversation, $assistant, $input, $outcome) : $this->no('Booking is switched off for this business.'),
                'save_customer_details' => $this->saveDetails($conversation, $input),
                'create_follow_up' => $this->followUp($conversation, $input, $outcome),
                'hand_over_to_team' => $this->handOver($conversation, $input, $outcome),
                default => $this->no("There is no tool called {$name}."),
            };
        } catch (ValidationException $e) {
            return $this->no(implode(' ', array_merge(...array_values($e->errors()))));
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, content: string, summary: string}
     */
    private function availableTimes(Conversation $conversation, array $input): array
    {
        $organization = $conversation->organization;
        $service = $this->service($conversation, $input['service_id'] ?? null);
        $duration = (int) ($service->duration_minutes ?? BookAppointment::DEFAULT_DURATION);
        $buffer = (int) ($service->buffer_minutes ?? 0);
        $timezone = $organization->timezoneOrDefault();

        if (filled($input['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input['date'])) {
            $slots = $this->availability->slots($organization, (string) $input['date'], $duration, $buffer, $service?->location_id, serviceId: $service?->id);
            $slots = array_slice($slots, 0, 12);
        } else {
            $slots = $this->availability->nextSlots($organization, CarbonImmutable::now($timezone), $duration, $buffer, $service?->location_id, limit: 6, serviceId: $service?->id);
        }

        if ($slots === []) {
            return ['ok' => true, 'content' => 'No free times found'.(filled($input['date'] ?? null) ? ' that day' : ' in the next two weeks').'. Offer another day or a follow-up from the team.', 'summary' => 'no free times'];
        }

        $list = array_map(fn (CarbonImmutable $s) => $s->setTimezone($timezone)->format('D j M g:i A').' (starts_at "'.$s->setTimezone($timezone)->format('Y-m-d H:i').'")', $slots);

        return ['ok' => true, 'content' => "Free times ({$timezone}, {$duration} minutes):\n- ".implode("\n- ", $list), 'summary' => count($slots).' times found'];
    }

    /**
     * @return array{ok: bool, content: string, summary: string}
     */
    private function searchKnowledge(Conversation $conversation, string $query): array
    {
        $items = KnowledgeItem::query()->forOrganization($conversation->organization)->forAgents()->search($query)->ordered()->limit(5)->get();
        if ($items->isEmpty()) {
            return ['ok' => true, 'content' => 'Nothing found. Don\'t guess: offer to check with the team.', 'summary' => 'nothing found for "'.Str::limit($query, 40).'"'];
        }

        return [
            'ok' => true,
            'content' => $items->map(fn (KnowledgeItem $i) => "## {$i->title} (".($i->visibility->value === 'public' ? 'can be shared' : 'internal').")\n".Str::limit(trim((string) $i->content), 1500))->implode("\n\n"),
            'summary' => $items->count().' items for "'.Str::limit($query, 40).'"',
        ];
    }

    /**
     * Same booking rules as a human agent: opening hours, the business's rules and the double-booking guard.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, content: string, summary: string}
     */
    private function bookAppointment(Conversation $conversation, AiAssistant $assistant, array $input, ToolOutcome $outcome): array
    {
        $organization = $conversation->organization;
        $timezone = $organization->timezoneOrDefault();
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', trim((string) ($input['starts_at'] ?? '')), $timezone);
        if (! $start) {
            return $this->no('Use starts_at exactly as returned by get_available_times (YYYY-MM-DD HH:MM).');
        }
        if (blank($input['phone'] ?? null) && blank($input['email'] ?? null) && ! $conversation->customer?->phone && ! $conversation->customer?->email) {
            return $this->no('Ask the customer for a phone number or email first.');
        }

        $customer = $this->identify($conversation, $input);
        $service = $this->service($conversation, $input['service_id'] ?? null);

        try {
            $appointment = $this->book->handle($organization, [
                'starts_at' => $start,
                'service_id' => $service?->id,
                'customer_id' => $customer?->id,
                'address' => $input['address'] ?? null,
                'notes' => trim('Booked by the AI assistant ('.$conversation->channel->label().'). '.($input['notes'] ?? '')),
                'status' => $assistant->bookings_need_confirmation ? AppointmentStatus::Pending : AppointmentStatus::Confirmed,
            ], null, 'ai', strict: true);
        } catch (SlotUnavailable $e) {
            $other = array_map(fn ($s) => $s->format('D j M g:i A').' (starts_at "'.$s->format('Y-m-d H:i').'")', $e->suggestions);

            return $this->no('That time was just taken.'.($other ? " Nearby free times:\n- ".implode("\n- ", $other) : ' Check get_available_times again.'));
        }

        $outcome->bookedAppointmentId = $appointment->id;
        $when = $appointment->starts_at->setTimezone($timezone)->format('l j F, g:i A');

        return [
            'ok' => true,
            'content' => $assistant->bookings_need_confirmation
                ? "Requested: {$appointment->title} on {$when}. It is pending: tell the customer the team will confirm it shortly."
                : "Booked and confirmed: {$appointment->title} on {$when}.",
            'summary' => 'booked '.$appointment->ulid.' for '.$when,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, content: string, summary: string}
     */
    private function saveDetails(Conversation $conversation, array $input): array
    {
        $customer = $this->identify($conversation, $input);

        return $customer
            ? ['ok' => true, 'content' => 'Saved.', 'summary' => 'saved details for customer '.$customer->ulid]
            : $this->no('Give at least a phone number or an email to save.');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, content: string, summary: string}
     */
    private function followUp(Conversation $conversation, array $input, ToolOutcome $outcome): array
    {
        $organization = $conversation->organization;
        $callBack = (bool) ($input['call_back'] ?? false);
        $name = $conversation->displayName();

        $task = $this->tasks->handle($organization, [
            'type' => $callBack ? TaskType::Callback : TaskType::FollowUp,
            'title' => Str::limit(($callBack ? 'Call back ' : 'Follow up with ').$name.' ('.$conversation->channel->label().')', 250, ''),
            'description' => Str::limit(trim((string) ($input['summary'] ?? '')), 2000, '')."\n\nConversation: ".route('app.inbox.show', $conversation),
            'priority' => $callBack ? 'high' : 'normal',
            'due_at' => $callBack ? $this->callbackDue->for($organization) : now()->addDay(),
            'customer_id' => $conversation->customer_id,
        ], null, 'ai');

        $outcome->taskIds[] = $task->id;

        return ['ok' => true, 'content' => 'Done: the team has a '.($callBack ? 'call-back' : 'follow-up').' task. Tell the customer when to expect contact in general terms (no exact time).', 'summary' => 'task '.$task->ulid];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, content: string, summary: string}
     */
    private function handOver(Conversation $conversation, array $input, ToolOutcome $outcome): array
    {
        [$type, $priority] = match ((string) ($input['category'] ?? 'unsure')) {
            'complaint' => [EscalationType::Complaint, null],
            'refund_request' => [EscalationType::RefundRequest, null],
            'pricing_approval' => [EscalationType::PricingApproval, null],
            'emergency' => [EscalationType::Emergency, EscalationPriority::Urgent],
            'urgent_issue' => [EscalationType::UrgentIssue, EscalationPriority::Urgent],
            'customer_asked' => [EscalationType::OwnerDecision, EscalationPriority::Normal],
            default => [EscalationType::AiUncertainty, EscalationPriority::Normal],
        };

        $escalation = $this->escalations->handle($conversation->organization, [
            'type' => $type,
            'priority' => $priority,
            'reason' => Str::limit('Message from '.$conversation->displayName().': '.trim((string) ($input['reason'] ?? 'needs a person')), 250, ''),
            'details' => 'Handed over by the AI assistant on '.$conversation->channel->label().'. Conversation: '.route('app.inbox.show', $conversation),
            'customer_id' => $conversation->customer_id,
        ], null, 'ai');

        $conversation->forceFill(['needs_human' => true, 'ai_paused' => true])->save();
        $outcome->handedOver = true;
        $outcome->escalationId = $escalation->id;

        return ['ok' => true, 'content' => 'Handed over. Tell the customer briefly that a member of the team will reply here, then stop.', 'summary' => 'handed over ('.$type->value.')'];
    }

    /**
     * Links the conversation to a customer, matched by phone then email, or created. Returns nothing about other customers.
     *
     * @param  array<string, mixed>  $input
     */
    private function identify(Conversation $conversation, array $input): ?Customer
    {
        $details = array_filter([
            'name' => $input['name'] ?? null,
            'phone' => $input['phone'] ?? null,
            'email' => $input['email'] ?? null,
            'address' => $input['address'] ?? null,
        ], fn ($v) => filled($v));

        if (! isset($details['phone']) && ! isset($details['email'])) {
            return $conversation->customer;
        }

        $match = $this->customers->handle($conversation->organization, $details, 'message');
        if ($match) {
            $conversation->forceFill(['customer_id' => $match['customer']->id])->save();
            $conversation->setRelation('customer', $match['customer']);
        }

        return $match['customer'] ?? $conversation->customer;
    }

    private function service(Conversation $conversation, mixed $id): ?BusinessService
    {
        return filled($id) ? BusinessService::query()->forOrganization($conversation->organization)->active()->whereKey((int) $id)->first() : null;
    }

    /**
     * @return array{ok: bool, content: string, summary: string}
     */
    private function no(string $message): array
    {
        return ['ok' => false, 'content' => $message, 'summary' => Str::limit($message, 120)];
    }
}
