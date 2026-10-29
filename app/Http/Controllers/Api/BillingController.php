<?php

namespace App\Http\Controllers\Api;

use App\Actions\Billing\ReportPayment;
use App\Exceptions\BillingUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CreditPurchase;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\Billing\AiCredits;
use App\Services\Billing\Entitlements;
use App\Services\Billing\InvoicePdf;
use App\Services\Billing\Stripe\CardPayments;
use App\Services\Billing\Trials;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Billing in the app (API-11): the plan, invoices with their lines and payments, the PDF, paying by card
 * on Stripe Checkout (D64), reporting a payment made another way, and turning automatic payments off.
 * Everyone with billing.view sees it; only billing.manage (owners) pays or changes anything.
 * Also the free trial (D97: keep the plan, or start one after it ended) and AI credits (D100).
 */
class BillingController extends Controller
{
    public function summary(Request $request, CurrentOrganization $current, Entitlements $entitlements, CardPayments $cards, Trials $trials, AiCredits $credits): JsonResponse
    {
        $organization = $current->get();
        $subscription = $entitlements->subscription($organization);
        $open = Invoice::query()->forOrganization($organization)->outstanding()->get();
        $pending = $trials->pending($organization);

        return ApiResponse::success([
            'plan' => $subscription ? [
                'name' => $subscription->plan->name,
                'status' => $subscription->status->value,
                'price_cents' => $subscription->price_cents,
                'currency' => $subscription->currency,
                'interval' => $subscription->interval,
                'trial_ends_on' => $subscription->trial_ends_on?->toDateString(),
                'renews_on' => $subscription->current_period_end->toDateString(),
                'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            ] : null,
            'balance_cents' => $open->sum(fn (Invoice $i) => $i->balanceCents()),
            'currency' => $organization->currency,
            'overdue' => $open->contains(fn (Invoice $i) => $i->isOverdue()),
            'card_payments' => $cards->enabled(),
            'autopay' => $organization->autopay_payment_method ? ['card' => $organization->autopay_card_label, 'since' => $organization->autopay_enabled_at?->toIso8601String()] : null,
            'can_pay' => $request->user()->hasPermissionIn('billing.manage', $organization),
            // A self-serve trial still to keep, or one that ended without a plan (read-only); null otherwise.
            'trial' => match (true) {
                $pending !== null => [
                    'status' => 'active',
                    'ends_on' => $pending->trial_ends_on?->toDateString(),
                    'days_left' => $trials->daysLeft($pending),
                    'calls_included' => $trials->includedCalls(),
                    'calls_used' => $trials->callsUsed($pending),
                    'plan_id' => $pending->plan_id,
                ],
                $trials->ended($organization) => ['status' => 'ended', 'ends_on' => null, 'days_left' => 0, 'calls_included' => null, 'calls_used' => 0, 'plan_id' => null],
                default => null,
            },
            'credits' => [
                'balance' => $credits->balance($organization),
                'packs' => collect($credits->packs())->map(fn (array $p, int $i) => ['pack' => $i, 'credits' => $p['credits'], 'price_cents' => $p['price_cents']])->values(),
                'pending' => CreditPurchase::query()->forOrganization($organization)->where('status', 'pending')->with('invoice:id,ulid')->latest('id')->get()
                    ->map(fn (CreditPurchase $p) => ['credits' => $p->credits, 'invoice_id' => $p->invoice?->ulid])->values(),
            ],
        ]);
    }

    /** The plans a business can choose itself (D98), for keeping a trial or starting a plan. */
    public function plans(CurrentOrganization $current, Entitlements $entitlements): JsonResponse
    {
        $currentPlan = $entitlements->subscription($current->get())?->plan_id;

        return ApiResponse::success(['plans' => Plan::query()->selfServe()->ordered()->get()->map(fn (Plan $plan) => [
            'id' => $plan->id,
            'name' => $plan->name,
            'tagline' => $plan->tagline,
            'price_cents' => $plan->price_cents,
            'currency' => $plan->currency,
            'interval' => $plan->interval,
            'highlights' => $plan->highlights(),
            'featured' => $plan->is_featured,
            'current' => $plan->id === $currentPlan,
        ])->values()]);
    }

    /** During a self-serve trial (D97): keep this plan for after the trial. Nothing is charged until then. */
    public function keepTrial(Request $request, CurrentOrganization $current, Trials $trials, Entitlements $entitlements): JsonResponse
    {
        $organization = $current->get();
        $plan = $this->plan($request);
        $pending = $trials->pending($organization) ?? abort(404);
        $trials->keep($pending, $plan, $request->user());
        $entitlements->forget($organization);

        return ApiResponse::success(['plan_id' => $plan->id], "Thank you! {$plan->name} carries on after your trial. Nothing is charged until then.");
    }

    /** After the trial ended (D97): a plan starts now, with its first invoice, and the account opens again. */
    public function startPlan(Request $request, CurrentOrganization $current, Trials $trials): JsonResponse
    {
        $organization = $current->get();
        $plan = $this->plan($request);
        $trials->restart($organization, $plan, $request->user());
        $invoice = Invoice::query()->forOrganization($organization)->where('status', '!=', 'draft')->latest('id')->first();

        return ApiResponse::success(['invoice' => $invoice ? $this->invoice($invoice) : null], "Welcome back! {$plan->name} is on.", 201);
    }

    /** Buy a pack of AI credits (D100): an invoice now, the credits once it's paid. */
    public function buyCredits(Request $request, CurrentOrganization $current, AiCredits $credits): JsonResponse
    {
        $pack = (int) $request->validate(['pack' => ['required', 'integer', 'min:0']])['pack'];
        $purchase = $credits->buy($current->get(), $pack, $request->user());
        $invoice = $purchase->invoice()->firstOrFail();

        return ApiResponse::success(['invoice' => $this->invoice($invoice), 'credits' => $purchase->credits], $invoice->balanceCents() <= 0
            ? number_format($purchase->credits).' AI credits added. Thank you!'
            : 'Invoice '.$invoice->number.' is ready. Your '.number_format($purchase->credits).' credits are added as soon as it\'s paid.', 201);
    }

    public function index(Request $request, CurrentOrganization $current): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:open,paid,all'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = Invoice::query()->forOrganization($current->get())->where('status', '!=', 'draft')
            ->when($request->query('status') === 'open', fn ($q) => $q->outstanding())
            ->when($request->query('status') === 'paid', fn ($q) => $q->where('status', 'paid'))
            ->latest('issued_at')->latest('id')->paginate((int) $request->query('per_page', 20));

        return ApiResponse::success(
            ['invoices' => $page->getCollection()->map(fn (Invoice $i) => $this->invoice($i))->values()],
            meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        );
    }

    public function show(CurrentOrganization $current, string $ulid): JsonResponse
    {
        $invoice = $this->find($current->get(), $ulid)->load(['items', 'payments']);

        return ApiResponse::success(['invoice' => $this->invoice($invoice) + [
            'items' => $invoice->items->map(fn (InvoiceItem $item) => ['description' => $item->description, 'quantity' => $item->quantity, 'unit_cents' => $item->unit_cents, 'amount_cents' => $item->amount_cents])->values(),
            'payments' => $invoice->payments->map(fn (Payment $p) => ['amount_cents' => $p->amount_cents, 'method' => $p->method->value, 'received_at' => $p->received_at->toIso8601String(), 'reference' => $p->reference])->values(),
            'notes' => $invoice->notes,
            'reported_paid_at' => $invoice->client_reported_paid_at?->toIso8601String(),
        ]]);
    }

    public function pdf(CurrentOrganization $current, InvoicePdf $pdf, string $ulid): Response
    {
        $invoice = $this->find($current->get(), $ulid);

        return response($pdf->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($invoice).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * A Stripe Checkout address for the invoice's balance (`save: true` also saves the card for automatic
     * payments). Open it in an in-app browser; Stripe returns to /app/billing?paid=…, which App Links bring
     * back into the app. The payment shows once Stripe confirms it.
     */
    public function pay(Request $request, CurrentOrganization $current, CardPayments $cards, string $ulid): JsonResponse
    {
        abort_unless($cards->enabled(), 404);
        $invoice = $this->find($current->get(), $ulid);
        $request->validate(['save' => ['nullable', 'boolean']]);
        $save = $request->boolean('save');
        if ($invoice->balanceCents() <= 0) {
            return ApiResponse::error('This invoice is already paid.', 409);
        }
        try {
            $url = $cards->checkoutUrl($invoice, $request->user(), $save);
        } catch (BillingUnavailable $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['checkout_url' => $url]);
    }

    /** "I've paid another way": the team confirms it (Payoneer, bank transfer). */
    public function reportPayment(Request $request, CurrentOrganization $current, ReportPayment $report, string $ulid): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'max:500']], ['note.required' => 'Tell us how and when you paid.'])['note'];
        $invoice = $report->handle($this->find($current->get(), $ulid), $request->user(), $note);

        return ApiResponse::success(['invoice' => $this->invoice($invoice)], 'Thanks! We\'ll confirm your payment shortly.');
    }

    public function stopAutopay(Request $request, CurrentOrganization $current, CardPayments $cards): JsonResponse
    {
        $cards->stopAutopay($current->get(), $request->user());

        return ApiResponse::success(['autopay' => null], 'Automatic payments are off. The card was removed.');
    }

    private function plan(Request $request): Plan
    {
        $id = (int) $request->validate(['plan_id' => ['required', 'integer']])['plan_id'];

        return Plan::query()->selfServe()->findOrFail($id);
    }

    private function find(Organization $organization, string $ulid): Invoice
    {
        return Invoice::query()->forOrganization($organization)->where('ulid', $ulid)->where('status', '!=', 'draft')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function invoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->ulid,
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'currency' => $invoice->currency,
            'total_cents' => $invoice->total_cents,
            'paid_cents' => $invoice->amount_paid_cents,
            'balance_cents' => $invoice->balanceCents(),
            'period' => $invoice->periodLabel(),
            'issued_at' => $invoice->issued_at->toIso8601String(),
            'due_at' => $invoice->due_at->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'overdue' => $invoice->isOverdue(),
            'autopay_error' => $invoice->autopay_error,
            'pdf_url' => route('api.client.billing.invoice.pdf', $invoice->ulid),
        ];
    }
}
