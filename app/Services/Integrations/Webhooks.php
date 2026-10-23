<?php

namespace App\Services\Integrations;

use App\Jobs\DeliverWebhook;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Escalation;
use App\Models\Organization;
use App\Models\PaymentRequest;
use App\Models\Review;
use App\Models\Survey;
use App\Models\Task;
use App\Models\VoiceCall;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Websites\DnsResolver;
use App\Services\Websites\SafeFetcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Outgoing webhooks (D87): events a business subscribed to are posted, signed, to its endpoints, from
 * the queue. The body is JSON: {id, event, created_at, organization, data}. The signature header is
 * "X-SureHelp-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "t.body" with the endpoint's secret>".
 */
class Webhooks
{
    /** Automation triggers that are also webhook events. */
    public const FROM_TRIGGERS = [
        'appointment_booked' => 'appointment.booked', 'appointment_rescheduled' => 'appointment.rescheduled',
        'appointment_completed' => 'appointment.completed', 'appointment_no_show' => 'appointment.no_show',
        'appointment_cancelled' => 'appointment.cancelled', 'call_logged' => 'call.logged', 'call_missed' => 'call.missed',
        'conversation_started' => 'conversation.started', 'customer_created' => 'customer.created',
        'escalation_raised' => 'escalation.raised', 'task_overdue' => 'task.overdue', 'review_received' => 'review.received',
    ];

    /** Seconds to wait before each retry. */
    public const BACKOFF = [60, 300, 1800, 7200, 21600];

    public function __construct(private readonly DnsResolver $dns) {}

    /** Queue the event for every endpoint of the business that wants it. */
    public function dispatch(string $event, Model $subject): int
    {
        $organizationId = (int) $subject->getAttribute('organization_id');
        $endpoints = WebhookEndpoint::withoutGlobalScopes()->where('organization_id', $organizationId)->where('is_active', true)->get()
            ->filter(fn (WebhookEndpoint $e) => $e->wants($event));
        if ($endpoints->isEmpty()) {
            return 0;
        }
        $organization = Organization::query()->find($organizationId);
        $body = ['id' => 'evt_'.Str::ulid(), 'event' => $event, 'created_at' => now()->toIso8601String(),
            'organization' => ['id' => $organization?->ulid, 'name' => $organization?->name], 'data' => $this->payload($subject)];
        foreach ($endpoints as $endpoint) {
            $delivery = $endpoint->deliveries()->create(['event' => $event, 'payload' => $body]);
            DeliverWebhook::dispatch($delivery->id)->afterCommit();
        }

        return $endpoints->count();
    }

    public function test(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $delivery = $endpoint->deliveries()->create(['event' => 'ping', 'payload' => ['id' => 'evt_'.Str::ulid(), 'event' => 'ping',
            'created_at' => now()->toIso8601String(), 'data' => ['message' => 'Hello from SureHelp. Your webhook works.']]]);
        $this->deliver($delivery);

        return $delivery->refresh();
    }

    /** One attempt. True when the endpoint answered 2xx. */
    public function deliver(WebhookDelivery $delivery): bool
    {
        $endpoint = $delivery->endpoint;
        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $t = time();
        $delivery->attempts++;
        $target = $this->target($endpoint->url);
        if (is_string($target)) {
            return $this->failed($delivery, null, $target);
        }
        [$host, $port, $ip] = $target;
        try {
            $response = Http::withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".(str_contains($ip, ':') ? "[{$ip}]" : $ip)]]])
                ->withHeaders(['X-SureHelp-Event' => $delivery->event, 'X-SureHelp-Delivery' => $delivery->ulid,
                    'X-SureHelp-Signature' => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $endpoint->secret)])
                ->withUserAgent('SureHelp-Webhooks/1.0')->connectTimeout(5)->timeout(10)
                ->withBody($body, 'application/json')->post($endpoint->url);
        } catch (\Throwable $e) {
            return $this->failed($delivery, null, 'No answer ('.class_basename($e).').');
        }
        if (! $response->successful()) {
            return $this->failed($delivery, $response->status(), 'Answered HTTP '.$response->status().'.');
        }
        $delivery->forceFill(['status' => 'delivered', 'response_code' => $response->status(), 'error' => null, 'delivered_at' => now()])->save();
        $endpoint->forceFill(['failures' => 0, 'last_delivery_at' => now()])->save();

        return true;
    }

    private function failed(WebhookDelivery $delivery, ?int $code, string $error): bool
    {
        $last = $delivery->attempts > count(self::BACKOFF) || $delivery->event === 'ping';
        $delivery->forceFill(['status' => $last ? 'failed' : 'pending', 'response_code' => $code, 'error' => mb_substr($error, 0, 300)])->save();
        $endpoint = $delivery->endpoint;
        if ($delivery->event !== 'ping') {
            $endpoint->failures++;
            if ($endpoint->failures >= WebhookEndpoint::MAX_FAILURES) {
                $endpoint->forceFill(['is_active' => false, 'disabled_reason' => 'Switched off after '.$endpoint->failures.' failed deliveries in a row: '.$error]);
            }
            $endpoint->save();
        }

        return false;
    }

    /**
     * Only https, only public addresses, pinned to the address we checked (no DNS rebinding).
     *
     * @return array{0: string, 1: int, 2: string}|string
     */
    public function target(string $url): array|string
    {
        $parts = parse_url($url);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return 'The address must start with https:// and have no user name or password.';
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->dns->ips($host);
        if ($ips === []) {
            return "We couldn't find {$host}.";
        }
        foreach ($ips as $ip) {
            if (! SafeFetcher::isPublic($ip)) {
                return 'That address points to a private network.';
            }
        }

        return [$host, (int) ($parts['port'] ?? 443), $ips[0]];
    }

    /** @return array<string, mixed> what the event is about, without internal ids */
    public function payload(Model $subject): array
    {
        $customer = fn (?Customer $c) => $c ? ['id' => $c->ulid, 'first_name' => $c->first_name, 'last_name' => $c->last_name, 'name' => $c->fullName(),
            'phone' => $c->phone_e164, 'email' => $c->email, 'status' => $c->getAttribute('status')?->value] : null;
        $iso = fn ($d) => $d?->toIso8601String();

        return match (true) {
            $subject instanceof Appointment => ['type' => 'appointment', 'id' => $subject->ulid, 'title' => $subject->title, 'status' => $subject->status->value,
                'starts_at' => $iso($subject->starts_at), 'ends_at' => $iso($subject->ends_at), 'address' => $subject->address, 'source' => $subject->source,
                'service' => $subject->service?->name, 'customer' => $customer($subject->customer)],
            $subject instanceof Customer => ['type' => 'customer'] + $customer($subject) + ['source' => $subject->source, 'created_at' => $iso($subject->created_at)],
            $subject instanceof CallLog => ['type' => 'call', 'id' => $subject->call_id, 'caller_name' => $subject->caller_name, 'caller_phone' => $subject->caller_phone,
                'reason' => $subject->reason_for_call, 'outcome' => $subject->call_outcome, 'notes' => $subject->notes, 'agent' => $subject->agent_name,
                'created_at' => $iso($subject->created_at), 'customer' => $customer($subject->customer)],
            $subject instanceof VoiceCall => ['type' => 'phone_call', 'from' => $subject->from_number, 'status' => $subject->status,
                'created_at' => $iso($subject->created_at), 'customer' => $customer($subject->customer)],
            $subject instanceof Conversation => ['type' => 'conversation', 'id' => $subject->ulid, 'channel' => $subject->channel->value, 'contact_name' => $subject->contact_name,
                'customer' => $customer($subject->customer)],
            $subject instanceof Escalation => ['type' => 'escalation', 'id' => $subject->ulid, 'reason' => $subject->reason, 'priority' => $subject->priority->value,
                'status' => $subject->status->value, 'customer' => $customer($subject->customer)],
            $subject instanceof Task => ['type' => 'task', 'id' => $subject->ulid, 'title' => $subject->title, 'status' => $subject->status->value,
                'due_at' => $iso($subject->due_at), 'customer' => $customer($subject->customer)],
            $subject instanceof Review => ['type' => 'review', 'id' => $subject->ulid, 'rating' => $subject->rating, 'comment' => $subject->comment,
                'author' => $subject->author_name, 'reviewed_at' => $iso($subject->reviewed_at)],
            $subject instanceof Survey => ['type' => 'survey', 'id' => $subject->ulid, 'score' => $subject->score, 'feedback' => $subject->feedback,
                'answered_at' => $iso($subject->answered_at), 'customer' => $customer($subject->customer)],
            $subject instanceof PaymentRequest => ['type' => 'payment', 'id' => $subject->ulid, 'kind' => $subject->kind, 'amount_cents' => $subject->amount_cents,
                'currency' => $subject->currency, 'description' => $subject->description, 'status' => $subject->status, 'paid_at' => $iso($subject->paid_at),
                'customer' => $customer($subject->customer)],
            default => ['type' => class_basename($subject)],
        };
    }
}
