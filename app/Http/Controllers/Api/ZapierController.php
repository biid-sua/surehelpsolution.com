<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Escalation;
use App\Models\Lead;
use App\Models\PaymentRequest;
use App\Models\Review;
use App\Models\Survey;
use App\Models\Task;
use App\Models\VoiceCall;
use App\Models\WebhookEndpoint;
use App\Services\Integrations\Webhooks;
use App\Services\Leads\LeadIntake;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * What the SureHelp Zapier app (D113) needs beyond the business API: checking the key, turning a Zap's
 * trigger on and off (REST hooks: a webhook address Zapier adds and removes itself), a real example of
 * each event for the Zap editor, and the "New lead" action, which gets the instant reply (D109).
 */
class ZapierController extends Controller
{
    /** Zapier's own hooks per business; Zaps beyond this are refused. */
    private const MAX_HOOKS = 50;

    public function me(Request $request, CurrentOrganization $current): JsonResponse
    {
        $organization = $current->get();

        return ApiResponse::success(['id' => $organization->ulid, 'business' => $organization->name, 'user' => $request->user()->name]);
    }

    public function subscribe(Request $request, CurrentOrganization $current, Webhooks $webhooks, Audit $audit): JsonResponse
    {
        $data = $request->validate([
            'target_url' => ['required', 'string', 'max:500', 'url:https'],
            'event' => ['required', 'string', Rule::in(array_keys(WebhookEndpoint::EVENTS))],
        ]);
        if (is_string($problem = $webhooks->target($data['target_url']))) {
            return ApiResponse::error($problem, 422, ['target_url' => [$problem]]);
        }
        $organization = $current->get();
        if (WebhookEndpoint::query()->forOrganization($organization)->where('source', 'zapier')->count() >= self::MAX_HOOKS) {
            return ApiResponse::error('This business has reached '.self::MAX_HOOKS.' Zaps with SureHelp triggers. Turn off ones you no longer use.', 422);
        }
        $endpoint = WebhookEndpoint::create(['organization_id' => $organization->id, 'url' => $data['target_url'], 'source' => 'zapier', 'events' => [$data['event']]]);
        $audit->record('webhook.created', $endpoint, new: ['url' => 'Zapier', 'events' => $endpoint->events], label: 'Zapier: '.$data['event']);

        return ApiResponse::success(['id' => $endpoint->ulid, 'event' => $data['event']], status: 201);
    }

    public function unsubscribe(CurrentOrganization $current, Audit $audit, string $ulid): JsonResponse
    {
        $endpoint = WebhookEndpoint::query()->forOrganization($current->get())->where('source', 'zapier')->where('ulid', $ulid)->firstOrFail();
        $audit->record('webhook.deleted', $endpoint, old: ['url' => 'Zapier', 'events' => $endpoint->events], label: 'Zapier: '.implode(', ', $endpoint->events));
        $endpoint->delete();

        return ApiResponse::success(['id' => $ulid]);
    }

    /** The newest real example of an event, shaped as the Zap will see it; a made-up one when there's none yet. */
    public function sample(Request $request, CurrentOrganization $current, Webhooks $webhooks): JsonResponse
    {
        $event = (string) $request->validate(['event' => ['required', 'string', Rule::in(array_keys(WebhookEndpoint::EVENTS))]])['event'];
        $organization = $current->get();
        $query = match (Str::before($event, '.')) {
            'appointment' => Appointment::query()->with(['customer', 'service'])->when($event !== 'appointment.booked', fn ($q) => $q->where('status', match ($event) {
                'appointment.completed' => 'completed', 'appointment.no_show' => 'no_show', 'appointment.cancelled' => 'cancelled', default => 'confirmed',
            })),
            'call' => $event === 'call.missed' ? VoiceCall::query()->with('customer')->where('status', 'missed') : CallLog::query()->with('customer'),
            'conversation' => Conversation::query()->with('customer'),
            'customer' => Customer::query(),
            'escalation' => Escalation::query()->with('customer'),
            'task' => Task::query()->with('customer'),
            'review' => Review::query(),
            'survey' => Survey::query()->with('customer')->whereNotNull('answered_at'),
            'payment' => PaymentRequest::query()->with('customer')->where('status', PaymentRequest::PAID),
            default => null,
        };
        /** @var Model|null $subject */
        $subject = $query?->where('organization_id', $organization->id)->latest('id')->first();
        $data = $subject ? $webhooks->payload($subject) : self::example($event);

        // Same shape as a live event in the app: the event's own id (Zapier skips ids it has seen, and
        // one booking can be moved twice), the record's id as record_id.
        return ApiResponse::success([['id' => 'evt_sample_'.str_replace('.', '_', $event), 'event' => $event, 'created_at' => now()->toIso8601String(),
            'record_id' => $data['id'] ?? null] + collect($data)->except('id')->all()]);
    }

    public function lead(Request $request, CurrentOrganization $current, LeadIntake $intake): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:190', 'required_without:phone'],
            'message' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', Rule::in(array_keys(Lead::SOURCES))],
        ]);
        $lead = $intake->receive($current->get(), $data['source'] ?? 'other', $data);

        return ApiResponse::success(['id' => $lead->ulid, 'name' => $lead->name, 'phone' => $lead->phone, 'email' => $lead->email,
            'source' => $lead->source, 'customer_id' => $lead->customer?->ulid, 'received_at' => $lead->received_at->toIso8601String()], status: 201);
    }

    /** @return array<string, mixed> */
    private static function example(string $event): array
    {
        $customer = ['id' => '01JEXAMPLECUSTOMER0000000000', 'first_name' => 'Ana', 'last_name' => 'Lopez', 'name' => 'Ana Lopez',
            'phone' => '+15125550147', 'email' => 'ana@example.com', 'status' => 'lead'];
        $at = now()->startOfHour()->addDay()->toIso8601String();

        return match (Str::before($event, '.')) {
            'appointment' => ['type' => 'appointment', 'id' => '01JEXAMPLEAPPOINTMENT00000000', 'title' => 'Water heater repair', 'status' => 'confirmed',
                'starts_at' => $at, 'ends_at' => $at, 'address' => '12 Oak St, Austin, TX 78704', 'source' => 'agent', 'service' => 'Water heater repair', 'customer' => $customer],
            'call' => ['type' => 'call', 'id' => 'CALL-EXAMPLE', 'caller_name' => 'Ana Lopez', 'caller_phone' => '+15125550147', 'reason' => 'Water heater leaking',
                'outcome' => 'booked', 'notes' => 'Booked for tomorrow morning.', 'agent' => 'John Carter', 'created_at' => now()->toIso8601String(), 'customer' => $customer],
            'payment' => ['type' => 'payment', 'id' => '01JEXAMPLEPAYMENT000000000000', 'kind' => 'deposit', 'amount_cents' => 5000, 'currency' => 'usd',
                'description' => 'Deposit for water heater repair', 'status' => 'paid', 'paid_at' => now()->toIso8601String(), 'customer' => $customer],
            'review' => ['type' => 'review', 'id' => '01JEXAMPLEREVIEW0000000000000', 'rating' => 5, 'comment' => 'Fast and friendly.', 'author' => 'Ana L.', 'reviewed_at' => now()->toIso8601String()],
            'customer' => ['type' => 'customer'] + $customer + ['source' => 'call', 'created_at' => now()->toIso8601String()],
            default => ['type' => Str::before($event, '.'), 'id' => '01JEXAMPLE0000000000000000000', 'customer' => $customer],
        };
    }
}
