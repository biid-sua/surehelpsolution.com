<?php

namespace App\Services\Billing;

use App\Actions\Billing\IssueInvoice;
use App\Enums\InvoiceStatus;
use App\Models\CreditEntry;
use App\Models\CreditPurchase;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Models\SiteEdit;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AI credits (D100). Each AI feature has a monthly allowance; credits pay for use beyond it, starting
 * with the website builder (D99). One credit buys `credits.tokens_per_credit` tokens.
 *
 * Buying a pack issues an invoice; its credits arrive when the invoice is paid (card, autopay or a
 * payment staff record), and a voided invoice cancels the purchase. The balance is the sum of
 * `credit_entries`, so every credit in or out is on record.
 */
class AiCredits
{
    public const PACKS_SETTING = 'credits.packs';

    public function __construct(private readonly Audit $audit) {}

    public function tokensPerCredit(): int
    {
        return max(1, (int) config('credits.tokens_per_credit', 1000));
    }

    /** Tokens as credits, rounded up: a change that used 2,100 tokens costs 3 credits. */
    public function creditsFor(int $tokens): int
    {
        return (int) ceil(max(0, $tokens) / $this->tokensPerCredit());
    }

    /** @return list<array{credits: int, price_cents: int}> */
    public function packs(): array
    {
        $saved = rescue(fn () => PlatformSetting::get(self::PACKS_SETTING), null, report: false);

        return $this->cleanPacks(is_array($saved) ? $saved : (array) config('credits.packs', []));
    }

    /**
     * @param  array<int, array<string, mixed>>  $packs
     *
     * @throws ValidationException
     */
    public function savePacks(array $packs, User $by): void
    {
        $clean = $this->cleanPacks($packs);
        if ($clean === []) {
            throw ValidationException::withMessages(['packs' => 'Offer at least one pack, with credits and a price above zero.']);
        }
        PlatformSetting::put(self::PACKS_SETTING, $clean);
        $this->audit->record('credits.packs_saved', null, new: ['packs' => $clean], actor: $by, label: 'AI credit packs');
    }

    public function balance(Organization $organization): int
    {
        return (int) CreditEntry::withoutGlobalScopes()->where('organization_id', $organization->id)->sum('credits');
    }

    /**
     * Buys a pack: an invoice now, the credits once it's paid.
     *
     * @throws ValidationException
     */
    public function buy(Organization $organization, int $pack, User $by): CreditPurchase
    {
        $offer = $this->packs()[$pack] ?? throw ValidationException::withMessages(['pack' => 'Choose one of the packs.']);
        if (! app(Entitlements::class)->subscription($organization)) {
            throw ValidationException::withMessages(['pack' => 'Choose a plan first: credits are added to your plan.']);
        }
        if (CreditPurchase::withoutGlobalScopes()->where('organization_id', $organization->id)->where('status', 'pending')->count() >= 3) {
            throw ValidationException::withMessages(['pack' => 'Pay the credit invoices you already have first.']);
        }

        // One transaction, so an automatic card charge (queued after commit) finds the purchase linked.
        return DB::transaction(function () use ($organization, $offer, $by) {
            $purchase = CreditPurchase::create([
                'organization_id' => $organization->id, 'credits' => $offer['credits'], 'price_cents' => $offer['price_cents'], 'user_id' => $by->id,
            ]);
            $invoice = app(IssueInvoice::class)->issue($organization, [[
                'description' => number_format($offer['credits']).' AI credits',
                'quantity' => 1,
                'unit_cents' => $offer['price_cents'],
            ]], 'USD', $by, notes: 'AI credits are added as soon as this invoice is paid.');
            $purchase->forceFill(['invoice_id' => $invoice->id])->save();
            $this->audit->record('credits.bought', $purchase, new: ['credits' => $offer['credits'], 'price_cents' => $offer['price_cents'], 'invoice' => $invoice->number],
                organization: $organization, actor: $by, label: number_format($offer['credits']).' credits');

            return $purchase;
        });
    }

    /** RecordPayment: a fully paid invoice for credits adds them (once). */
    public function invoicePaid(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Paid) {
            return;
        }
        CreditPurchase::withoutGlobalScopes()->where('invoice_id', $invoice->id)->where('status', 'pending')->get()
            ->each(function (CreditPurchase $purchase) {
                DB::transaction(function () use ($purchase) {
                    $claimed = CreditPurchase::withoutGlobalScopes()->whereKey($purchase->id)->where('status', 'pending')
                        ->update(['status' => 'granted', 'granted_at' => now()]);
                    if ($claimed) {
                        CreditEntry::create([
                            'organization_id' => $purchase->organization_id, 'credits' => $purchase->credits, 'kind' => 'purchase',
                            'note' => number_format($purchase->credits).' credits bought', 'credit_purchase_id' => $purchase->id, 'user_id' => $purchase->user_id,
                        ]);
                    }
                });
            });
    }

    /** VoidInvoice: an unpaid credit invoice that's voided cancels the purchase. */
    public function invoiceVoided(Invoice $invoice): void
    {
        CreditPurchase::withoutGlobalScopes()->where('invoice_id', $invoice->id)->where('status', 'pending')->update(['status' => 'cancelled']);
    }

    /**
     * Staff add credits (a gift, a refund) or take some back; the balance never goes below zero.
     *
     * @throws ValidationException
     */
    public function grant(Organization $organization, int $credits, string $note, User $by): CreditEntry
    {
        $note = trim($note);
        if ($credits === 0 || abs($credits) > 100000) {
            throw ValidationException::withMessages(['credits' => 'Enter a number of credits, up to 100,000.']);
        }
        if ($note === '') {
            throw ValidationException::withMessages(['note' => 'Say why, for the business\'s history.']);
        }
        if ($credits < 0 && $this->balance($organization) + $credits < 0) {
            throw ValidationException::withMessages(['credits' => 'That would take the balance below zero. It has '.number_format($this->balance($organization)).'.']);
        }

        $entry = CreditEntry::create(['organization_id' => $organization->id, 'credits' => $credits, 'kind' => 'grant', 'note' => mb_substr($note, 0, 200), 'user_id' => $by->id]);
        $this->audit->record('credits.granted', $entry, new: ['credits' => $credits, 'note' => $note], organization: $organization, actor: $by, label: $organization->name);

        return $entry;
    }

    /**
     * A website change finished: the tokens beyond what was left of the monthly allowance come off
     * the credit balance, rounded up to whole credits. A rare overshoot past the balance isn't charged.
     */
    public function chargeSiteEdit(SiteEdit $edit, int $allowanceRemainingBefore): int
    {
        $tokens = $edit->input_tokens + $edit->output_tokens;
        $credits = $this->creditsFor($tokens - max(0, $allowanceRemainingBefore));
        $organization = Organization::query()->find($edit->organization_id);
        if ($credits <= 0 || ! $organization) {
            return 0;
        }
        $credits = min($credits, max(0, $this->balance($organization)));
        if ($credits === 0) {
            return 0;
        }

        CreditEntry::query()->firstOrCreate(['site_edit_id' => $edit->id], [
            'organization_id' => $edit->organization_id, 'credits' => -$credits, 'kind' => 'usage',
            'note' => 'Website change: '.mb_substr($edit->prompt, 0, 150), 'user_id' => $edit->user_id,
        ]);

        return $credits;
    }

    public function priceLabel(array $pack): string
    {
        return Money::format($pack['price_cents'], 'USD');
    }

    /**
     * @param  array<int, mixed>  $packs
     * @return list<array{credits: int, price_cents: int}>
     */
    private function cleanPacks(array $packs): array
    {
        $clean = collect($packs)->filter(fn ($p) => is_array($p))
            ->map(fn (array $p) => ['credits' => (int) ($p['credits'] ?? 0), 'price_cents' => (int) ($p['price_cents'] ?? 0)])
            ->filter(fn (array $p) => $p['credits'] > 0 && $p['credits'] <= 1000000 && $p['price_cents'] > 0)
            ->sortBy('credits')->unique('credits')->take((int) config('credits.max_packs', 6))->values()->all();

        return $clean;
    }
}
