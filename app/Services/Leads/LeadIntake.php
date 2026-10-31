<?php

namespace App\Services\Leads;

use App\Actions\Customers\MatchOrCreateCustomer;
use App\Actions\Notifications\NotifyOrganization;
use App\Actions\Tasks\CreateTask;
use App\Enums\TaskType;
use App\Jobs\RespondToLead;
use App\Models\Lead;
use App\Models\Organization;
use App\Notifications\LeadReceived;
use App\Services\Tasks\CallbackDueTime;
use App\Support\Phone;
use Illuminate\Support\Str;

/**
 * Every new lead comes in here (D109), from the website form or the business's lead link. It
 * becomes a customer, a high-priority call-back task and an alert for the team, and gets its
 * instant reply right after (RespondToLead).
 */
class LeadIntake
{
    /** The same person again within this many minutes is the same lead (lead sources retry). */
    private const DUPLICATE_MINUTES = 10;

    public function __construct(
        private readonly MatchOrCreateCustomer $customers,
        private readonly CreateTask $tasks,
        private readonly CallbackDueTime $callbackDue,
        private readonly NotifyOrganization $notify,
    ) {}

    /**
     * @param  array{name?: ?string, phone?: ?string, email?: ?string, message?: ?string, page?: ?string, details?: array<string, mixed>}  $data
     */
    public function receive(Organization $organization, string $source, array $data): Lead
    {
        $source = array_key_exists($source, Lead::SOURCES) ? $source : 'other';
        $name = Str::limit(trim(strip_tags((string) ($data['name'] ?? ''))), 150, '') ?: null;
        $phone = Phone::normalize((string) ($data['phone'] ?? '')) ?? (Str::limit(trim((string) ($data['phone'] ?? '')), 30, '') ?: null);
        $email = filter_var(trim((string) ($data['email'] ?? '')), FILTER_VALIDATE_EMAIL) ? Str::lower(trim((string) $data['email'])) : null;
        $message = Str::limit(trim(strip_tags((string) ($data['message'] ?? ''))), 2000, '') ?: null;

        $duplicate = Lead::query()->forOrganization($organization)->where('received_at', '>=', now()->subMinutes(self::DUPLICATE_MINUTES))
            ->where(fn ($q) => $q->when($phone, fn ($q) => $q->orWhere('phone', $phone))->when($email, fn ($q) => $q->orWhere('email', $email)))
            ->when(! $phone && ! $email, fn ($q) => $q->whereRaw('1 = 0'))->latest('id')->first();
        if ($duplicate) {
            return $duplicate;
        }

        $customer = ($phone || $email)
            ? ($this->customers->handle($organization, ['name' => $name, 'phone' => $phone, 'email' => $email], $source === 'website' ? 'website' : 'lead')['customer'] ?? null)
            : null;
        $label = Lead::SOURCES[$source];
        $page = Str::limit(trim((string) ($data['page'] ?? '')), 300, '');

        $task = $this->tasks->handle($organization, [
            'type' => $phone ? TaskType::Callback : TaskType::FollowUp,
            'title' => Str::limit(($source === 'website' ? 'Website enquiry from ' : 'New lead from ').($name ?: 'someone').($source === 'website' ? '' : ' ('.$label.')'), 250, ''),
            'description' => trim(($message ?? '').($page !== '' ? "\n\nSent from: {$page}" : '')
                ."\n\n".collect(['Phone' => $phone, 'Email' => $email])->filter()->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n")),
            'priority' => 'high',
            'due_at' => $phone ? $this->callbackDue->for($organization) : now()->addDay(),
            'customer_id' => $customer?->id,
        ], null, $source === 'website' ? 'website' : 'lead');

        $lead = Lead::create([
            'organization_id' => $organization->id, 'customer_id' => $customer?->id, 'task_id' => $task->id, 'source' => $source,
            'name' => $name, 'phone' => $phone, 'email' => $email, 'message' => $message,
            'details' => array_filter(['page' => $page ?: null, ...array_slice((array) ($data['details'] ?? []), 0, 20)], fn ($v) => $v !== null && $v !== '') ?: null,
            'received_at' => now(),
        ]);

        $this->notify->handle($organization, new LeadReceived($lead), 'customers.view');
        // The instant reply: right after this request is answered, not when the queue next runs.
        RespondToLead::dispatchAfterResponse($lead->id);

        return $lead;
    }
}
