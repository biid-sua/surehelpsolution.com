<?php

namespace App\Actions\Billing;

use App\Actions\Notifications\NotifyOrganization;
use App\Enums\InvoiceStatus;
use App\Models\BusinessLocation;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\InvoiceIssued;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\InvoiceNumbers;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues invoices: one per subscription period (idempotent), or a one-off invoice from the admin console.
 */
class IssueInvoice
{
    public function __construct(
        private readonly InvoiceNumbers $numbers,
        private readonly BillingSettings $settings,
        private readonly Audit $audit,
        private readonly NotifyOrganization $notify,
    ) {}

    /**
     * The invoice for the subscription's current period; issued once, however often billing runs.
     */
    public function forPeriod(Subscription $subscription, ?User $actor = null): Invoice
    {
        $existing = Invoice::withoutGlobalScopes()->where('subscription_id', $subscription->id)
            ->whereDate('period_start', $subscription->current_period_start->toDateString())->first();
        if ($existing) {
            return $existing;
        }

        $plan = $subscription->plan;
        $start = CarbonImmutable::parse($subscription->current_period_start->toDateString());
        $end = CarbonImmutable::parse($subscription->current_period_end->toDateString());
        $label = $start->format('M j, Y').' – '.$end->subDay()->format('M j, Y');

        try {
            return $this->issue($subscription->organization, [
                ['description' => "{$plan->name} plan · {$label}", 'quantity' => 1, 'unit_cents' => $subscription->price_cents],
            ], $subscription->currency, $actor, [
                'subscription_id' => $subscription->id,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A parallel billing run issued it a moment ago.
            return Invoice::withoutGlobalScopes()->where('subscription_id', $subscription->id)->whereDate('period_start', $start->toDateString())->firstOrFail();
        }
    }

    /**
     * @param  list<array{description: string, quantity: int, unit_cents: int}>  $items
     * @param  array<string, mixed>  $extra
     *
     * @throws ValidationException for an empty or negative invoice
     */
    public function issue(Organization $organization, array $items, string $currency = 'USD', ?User $actor = null, array $extra = [], ?string $notes = null): Invoice
    {
        $total = array_sum(array_map(fn (array $i) => $i['quantity'] * $i['unit_cents'], $items));
        if ($items === [] || $total <= 0) {
            throw ValidationException::withMessages(['items' => ['An invoice needs at least one line and a total above zero.']]);
        }

        $invoice = DB::transaction(function () use ($organization, $items, $currency, $extra, $notes, $total) {
            $invoice = Invoice::create($extra + [
                'number' => $this->numbers->next(),
                'organization_id' => $organization->id,
                'status' => InvoiceStatus::Open,
                'currency' => $currency,
                'subtotal_cents' => $total,
                'total_cents' => $total,
                'issued_at' => now(),
                'due_at' => now()->addDays($this->settings->dueDays())->endOfDay(),
                'billing_details' => $this->billTo($organization),
                'notes' => $notes,
            ]);

            foreach ($items as $item) {
                $invoice->items()->create($item + ['amount_cents' => $item['quantity'] * $item['unit_cents']]);
            }

            return $invoice;
        });

        $this->audit->record('invoice.issued', $invoice, new: ['number' => $invoice->number, 'total_cents' => $total, 'currency' => $currency],
            organization: $organization, actor: $actor, label: $invoice->number);
        $this->notify->handle($organization, new InvoiceIssued($invoice), 'billing.view');

        return $invoice;
    }

    /**
     * Who the invoice is addressed to, frozen at issue time (later profile edits don't rewrite history).
     *
     * @return array<string, mixed>
     */
    private function billTo(Organization $organization): array
    {
        $location = BusinessLocation::query()->forOrganization($organization)->orderByDesc('is_primary')->orderBy('id')->first();
        $profile = $organization->profile;

        return array_filter([
            'name' => $profile->legal_name ?? $organization->name,
            'email' => $profile->email ?? $organization->owner?->email,
            'phone' => $profile?->phone,
            'address' => $location ? collect([$location->address_line1, $location->address_line2, trim(implode(', ', array_filter([$location->city, trim($location->state.' '.$location->postal_code)])))])->filter()->implode("\n") : null,
        ]);
    }
}
