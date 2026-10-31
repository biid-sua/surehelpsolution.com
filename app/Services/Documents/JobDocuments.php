<?php

namespace App\Services\Documents;

use App\Actions\Customers\RecordTimelineEvent;
use App\Actions\Notifications\NotifyOrganization;
use App\Enums\TimelineEventType;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\JobDocument;
use App\Models\Organization;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Notifications\CustomerEmail;
use App\Notifications\EstimateAnswered;
use App\Services\Business\Branding;
use App\Services\Payments\CustomerPayments;
use App\Services\Telephony\Texting;
use App\Support\Audit\Audit;
use App\Support\DemoGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Estimates and invoices to customers (D115): written in the portal, sent by text and email with a
 * link, accepted or declined by the customer, turned into an invoice, and paid by card through the
 * business's own Stripe account (D85) or marked paid by hand. The only place their status changes
 * (docs/state-machines.md).
 */
class JobDocuments
{
    public const MAX_ITEMS = 50;

    public function __construct(
        private readonly CustomerPayments $payments,
        private readonly Texting $texting,
        private readonly Branding $branding,
        private readonly RecordTimelineEvent $timeline,
        private readonly NotifyOrganization $notify,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array{title: string, items: list<array{description: string, quantity: float|int|string, unit_cents: int}>, notes?: ?string, tax_rate_bp?: int, valid_until?: ?string, due_on?: ?string, appointment_id?: ?int, estimate_id?: ?int}  $data
     */
    public function create(Organization $organization, string $type, Customer $customer, array $data, User $by): JobDocument
    {
        if (! in_array($type, [JobDocument::ESTIMATE, JobDocument::INVOICE], true) || $customer->organization_id !== $organization->id) {
            throw new InvalidArgumentException('Not a document this business can make.');
        }

        return DB::transaction(function () use ($organization, $type, $customer, $data, $by) {
            $document = JobDocument::create([
                'organization_id' => $organization->id, 'customer_id' => $customer->id, 'appointment_id' => $data['appointment_id'] ?? null,
                'estimate_id' => $data['estimate_id'] ?? null, 'created_by_user_id' => $by->id, 'type' => $type,
                'number' => $this->nextNumber($organization, $type), 'status' => JobDocument::DRAFT,
            ] + $this->contents($data));
            $this->audit->record('documents.created', $document, new: ['total' => $document->total()], label: $document->label());

            return $document;
        });
    }

    /** Drafts and sent documents can still be changed; the link shows the new version. */
    public function update(JobDocument $document, array $data, User $by): JobDocument
    {
        if (! in_array($document->status, [JobDocument::DRAFT, JobDocument::SENT], true)) {
            throw ValidationException::withMessages(['title' => 'This '.$document->type.' can\'t be changed any more.']);
        }
        $old = $document->total();
        $document->forceFill($this->contents($data))->save();
        $this->audit->record('documents.updated', $document, old: ['total' => $old], new: ['total' => $document->total()], actor: $by, label: $document->label());

        return $document;
    }

    /**
     * Text and/or email the link. Texts follow the texting rules (D61: opt-outs, quiet line); email
     * goes only to a valid address. A draft becomes sent once it went out at least one way.
     *
     * @param  list<string>  $channels
     * @return list<string> how it went out
     */
    public function send(JobDocument $document, array $channels, User $by): array
    {
        if (! in_array($document->status, [JobDocument::DRAFT, JobDocument::SENT], true)) {
            throw ValidationException::withMessages(['channels' => 'This '.$document->type.' can\'t be sent any more.']);
        }
        $organization = Organization::query()->findOrFail($document->organization_id);
        if (DemoGuard::isDemo($organization->id)) {
            throw ValidationException::withMessages(['channels' => 'Demo businesses don\'t send anything to customers.']);
        }
        $customer = $document->customer;
        $business = $this->branding->for($organization)['name'];
        $first = $customer->first_name ?: 'there';
        $what = $document->isEstimate() ? 'an estimate' : 'an invoice';
        $sent = [];

        if (in_array('sms', $channels, true) && $customer->phone_e164 && $this->texting->enabled() && $this->texting->lineFor($organization)) {
            $text = "Hi {$first}, {$business} sent you {$what} for {$document->title} ({$document->total()}). "
                .($document->isEstimate() ? 'See it and accept it here: ' : 'See it and pay here: ').$document->link();
            $message = $this->texting->sendAutomatic($organization, $customer->phone_e164, $text, $document->type);
            if ($message && $message->status !== 'failed') {
                $sent[] = 'sms';
            }
        }
        if (in_array('email', $channels, true) && filter_var($customer->email, FILTER_VALIDATE_EMAIL) !== false) {
            $replyTo = BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->value('email');
            Notification::route('mail', $customer->email)->notify(new CustomerEmail(
                ($document->isEstimate() ? 'Your estimate from ' : 'Your invoice from ').$business,
                "Hi {$first},\n\n{$business} sent you {$what} for {$document->title}: {$document->total()}."
                    .($document->isEstimate() ? "\n\nYou can look it over and accept it online." : "\n\nYou can look it over and pay it online."),
                $business, $replyTo ?: null, $document->link(), $document->isEstimate() ? 'See the estimate' : 'See and pay the invoice', organizationId: $organization->id,
            ));
            $sent[] = 'email';
        }
        if ($sent === []) {
            throw ValidationException::withMessages(['channels' => 'It couldn\'t be sent: the customer has no mobile number we can text or email address.']);
        }
        $document->forceFill([
            'status' => JobDocument::SENT, 'sent_at' => now(), 'sent_via' => array_values(array_unique([...($document->sent_via ?? []), ...$sent])),
        ])->save();
        $this->timeline->handle($customer, $sent === ['email'] ? TimelineEventType::EmailSent : TimelineEventType::SmsSent,
            $document->label().' sent: '.$document->total(), $document->title, null, ['document' => $document->ulid]);
        $this->audit->record('documents.sent', $document, new: ['via' => $sent], actor: $by, label: $document->label());

        return $sent;
    }

    /** The customer opened the link (first time only). */
    public function viewed(JobDocument $document): void
    {
        if (! $document->viewed_at && $document->status === JobDocument::SENT) {
            $document->forceFill(['viewed_at' => now()])->save();
        }
    }

    public function accept(JobDocument $document, string $name): void
    {
        $this->answerable($document);
        $document->forceFill(['status' => JobDocument::ACCEPTED, 'accepted_at' => now(), 'accepted_name' => mb_substr(trim($name), 0, 120)])->save();
        $this->answered($document);
    }

    public function decline(JobDocument $document, ?string $reason): void
    {
        $this->answerable($document);
        $document->forceFill(['status' => JobDocument::DECLINED, 'declined_at' => now(), 'declined_reason' => $reason ? mb_substr(trim($reason), 0, 500) : null])->save();
        $this->answered($document);
    }

    /** An invoice with the estimate's lines; the estimate is then "invoiced". */
    public function invoiceFrom(JobDocument $estimate, User $by): JobDocument
    {
        if (! $estimate->isEstimate() || ! in_array($estimate->status, [JobDocument::ACCEPTED, JobDocument::SENT, JobDocument::DRAFT], true)) {
            throw ValidationException::withMessages(['title' => 'This estimate can\'t be turned into an invoice.']);
        }
        $organization = Organization::query()->findOrFail($estimate->organization_id);

        return DB::transaction(function () use ($estimate, $organization, $by) {
            $invoice = $this->create($organization, JobDocument::INVOICE, $estimate->customer, [
                'title' => $estimate->title, 'items' => $estimate->items, 'notes' => $estimate->notes, 'tax_rate_bp' => $estimate->tax_rate_bp,
                'due_on' => now($organization->timezoneOrDefault())->addDays(14)->toDateString(), 'appointment_id' => $estimate->appointment_id, 'estimate_id' => $estimate->id,
            ], $by);
            $estimate->forceFill(['status' => JobDocument::INVOICED])->save();

            return $invoice;
        });
    }

    /**
     * Where the customer pays an invoice by card: a payment request (D85) for the total, made once.
     * Null when the business can't take card payments.
     */
    public function checkoutUrl(JobDocument $invoice): ?string
    {
        $organization = Organization::query()->findOrFail($invoice->organization_id);
        if ($invoice->isEstimate() || $invoice->status !== JobDocument::SENT || ! $this->payments->ready($organization)) {
            return null;
        }
        $request = $invoice->paymentRequest;
        if (! $request || $request->status !== PaymentRequest::OPEN || $request->amount_cents !== $invoice->total_cents) {
            $request = $this->payments->request($organization, [
                'customer_id' => $invoice->customer_id, 'appointment_id' => $invoice->appointment_id, 'kind' => 'invoice',
                'description' => $invoice->label().': '.$invoice->title, 'amount_cents' => $invoice->total_cents,
            ]);
            $invoice->forceFill(['payment_request_id' => $request->id])->save();
        }

        return $this->payments->checkoutUrl($request);
    }

    /** Stripe confirmed the card payment for an invoice's payment request. */
    public function paidOnline(PaymentRequest $request): void
    {
        $invoice = JobDocument::withoutGlobalScopes()->where('payment_request_id', $request->id)->where('type', JobDocument::INVOICE)->first();
        if ($invoice && $invoice->status === JobDocument::SENT) {
            $invoice->forceFill(['status' => JobDocument::PAID, 'paid_at' => now(), 'paid_how' => 'card'])->save();
        }
    }

    /** Paid some other way: cash, check, bank transfer. */
    public function markPaid(JobDocument $invoice, string $how, User $by): void
    {
        if ($invoice->isEstimate() || ! in_array($invoice->status, [JobDocument::DRAFT, JobDocument::SENT], true)) {
            throw ValidationException::withMessages(['paid_how' => 'This invoice can\'t be marked as paid.']);
        }
        $invoice->forceFill(['status' => JobDocument::PAID, 'paid_at' => now(), 'paid_how' => in_array($how, ['cash', 'check', 'transfer', 'other'], true) ? $how : 'other'])->save();
        $this->audit->record('documents.paid', $invoice, new: ['how' => $invoice->paid_how], actor: $by, label: $invoice->label());
    }

    public function void(JobDocument $document, User $by): void
    {
        if (! in_array($document->status, [JobDocument::DRAFT, JobDocument::SENT, JobDocument::ACCEPTED, JobDocument::DECLINED], true)) {
            throw ValidationException::withMessages(['title' => 'This '.$document->type.' can\'t be voided.']);
        }
        if ($document->paymentRequest?->status === PaymentRequest::OPEN) {
            $this->payments->cancel($document->paymentRequest, $by);
        }
        $document->forceFill(['status' => JobDocument::VOID])->save();
        $this->audit->record('documents.voided', $document, actor: $by, label: $document->label());
    }

    /**
     * Lines and totals. Quantities may have two decimals; amounts are cents; tax in basis points.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function contents(array $data): array
    {
        $items = [];
        foreach (array_slice((array) ($data['items'] ?? []), 0, self::MAX_ITEMS) as $item) {
            $description = trim((string) ($item['description'] ?? ''));
            $quantity = round((float) ($item['quantity'] ?? 1), 2);
            if ($description === '' || $quantity <= 0) {
                continue;
            }
            $items[] = ['description' => mb_substr($description, 0, 300), 'quantity' => $quantity, 'unit_cents' => max(0, (int) ($item['unit_cents'] ?? 0))];
        }
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one line.']);
        }
        $subtotal = array_sum(array_map(fn (array $i) => (int) round($i['quantity'] * $i['unit_cents']), $items));
        $rate = max(0, min(3000, (int) ($data['tax_rate_bp'] ?? 0)));
        $tax = (int) round($subtotal * $rate / 10000);

        return [
            'title' => mb_substr(trim((string) $data['title']), 0, 150), 'items' => $items, 'notes' => filled($data['notes'] ?? null) ? mb_substr(trim((string) $data['notes']), 0, 5000) : null,
            'subtotal_cents' => $subtotal, 'tax_rate_bp' => $rate, 'tax_cents' => $tax, 'total_cents' => $subtotal + $tax,
            'valid_until' => $data['valid_until'] ?? null, 'due_on' => $data['due_on'] ?? null,
        ];
    }

    private function nextNumber(Organization $organization, string $type): string
    {
        $prefix = $type === JobDocument::ESTIMATE ? 'EST-' : 'INV-';
        $last = JobDocument::withoutGlobalScopes()->where('organization_id', $organization->id)->where('type', $type)->lockForUpdate()->count();

        return $prefix.str_pad((string) ($last + 1001), 4, '0', STR_PAD_LEFT);
    }

    private function answerable(JobDocument $document): void
    {
        if (! $document->isEstimate() || ! $document->isOpen()) {
            throw ValidationException::withMessages(['answer' => 'This estimate can no longer be answered. Please contact the business.']);
        }
    }

    private function answered(JobDocument $document): void
    {
        $organization = Organization::query()->findOrFail($document->organization_id);
        $accepted = $document->status === JobDocument::ACCEPTED;
        $this->timeline->handle($document->customer, TimelineEventType::NoteAdded, ($accepted ? 'Accepted ' : 'Declined ').$document->label(),
            $accepted ? 'Signed as '.$document->accepted_name : $document->declined_reason, null, ['document' => $document->ulid]);
        $this->notify->handle($organization, new EstimateAnswered($document), 'payments.view');
        $this->audit->record($accepted ? 'documents.accepted' : 'documents.declined', $document, organization: $organization, label: $document->label());
    }
}
