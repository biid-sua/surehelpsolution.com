<?php

namespace App\Services\Billing;

use App\Actions\Notifications\NotifyOrganization;
use App\Enums\InvoiceStatus;
use App\Models\BillingCredit;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Referral;
use App\Models\Subscription;
use App\Notifications\ReferralRewarded;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The referral programme (D90): each business has a link (/start?ref=CODE). A business that signs up
 * with it and pays its first invoice earns the referrer one month of its plan as credit, up to
 * referrals.max_per_year rewards. Credits come off the next invoices automatically.
 */
class Referrals
{
    public function __construct(private readonly NotifyOrganization $notify, private readonly Audit $audit) {}

    public function code(Organization $organization): string
    {
        if (! $organization->referral_code) {
            do {
                $code = Str::upper(Str::random(8));
            } while (Organization::query()->where('referral_code', $code)->exists());
            $organization->forceFill(['referral_code' => $code])->save();
        }

        return (string) $organization->referral_code;
    }

    public function link(Organization $organization): string
    {
        return route('signup', ['ref' => $this->code($organization)]);
    }

    /** A new business signed up with someone's link. */
    public function signedUp(Organization $referred, ?string $code): ?Referral
    {
        $code = Str::upper(trim((string) $code));
        if (! config('referrals.enabled', true) || $code === '' || ! preg_match('/^[A-Z0-9]{6,12}$/', $code)) {
            return null;
        }
        $referrer = Organization::query()->where('referral_code', $code)->first();
        if (! $referrer || $referrer->id === $referred->id || Referral::query()->where('referred_organization_id', $referred->id)->exists()) {
            return null;
        }

        return Referral::create(['referrer_organization_id' => $referrer->id, 'referred_organization_id' => $referred->id]);
    }

    /** An invoice was paid: the first one of a referred business rewards its referrer. */
    public function invoicePaid(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Paid || $invoice->total_cents <= 0) {
            return;
        }
        DB::transaction(function () use ($invoice) {
            $referral = Referral::query()->where('referred_organization_id', $invoice->organization_id)->where('status', 'signed_up')->lockForUpdate()->first();
            if (! $referral) {
                return;
            }
            $referrer = $referral->referrer;
            $year = Referral::query()->where('referrer_organization_id', $referrer->id)->where('status', 'rewarded')->where('rewarded_at', '>=', now()->subYear())->count();
            $subscription = Subscription::withoutGlobalScopes()->where('organization_id', $referrer->id)->latest('id')->first();
            $credit = (int) ($subscription->price_cents ?? 0);
            if ($year >= (int) config('referrals.max_per_year', 12) || $credit <= 0) {
                $referral->forceFill(['status' => 'rewarded', 'rewarded_at' => now(), 'credit_cents' => 0])->save();

                return;
            }
            $referral->forceFill(['status' => 'rewarded', 'rewarded_at' => now(), 'credit_cents' => $credit])->save();
            BillingCredit::create(['organization_id' => $referrer->id, 'referral_id' => $referral->id, 'amount_cents' => $credit,
                'currency' => $subscription->currency ?? 'USD', 'reason' => 'Referral: '.$referral->referred->name]);
            DB::afterCommit(function () use ($referrer, $referral) {
                $this->notify->handle($referrer, new ReferralRewarded($referral), 'billing.view');
                $this->audit->record('referral.rewarded', $referral, new: ['credit_cents' => $referral->credit_cents], organization: $referrer, label: $referral->referred->name);
            });
        });
    }

    /**
     * Credit lines for an invoice of $subtotal, used up oldest first. Call inside the invoice's transaction.
     *
     * @return list<array{description: string, quantity: int, unit_cents: int}>
     */
    public function apply(Organization $organization, int $subtotal): array
    {
        $lines = [];
        $left = $subtotal;
        foreach (BillingCredit::withoutGlobalScopes()->where('organization_id', $organization->id)->whereColumn('used_cents', '<', 'amount_cents')->orderBy('id')->lockForUpdate()->get() as $credit) {
            if ($left <= 0) {
                break;
            }
            $use = min($left, $credit->remaining());
            $credit->forceFill(['used_cents' => $credit->used_cents + $use])->save();
            $lines[] = ['description' => 'Credit · '.$credit->reason, 'quantity' => 1, 'unit_cents' => -$use];
            $left -= $use;
        }

        return $lines;
    }

    /** @return array{signed_up: int, rewarded: int, credit_left: int} */
    public function summary(Organization $organization): array
    {
        return [
            'signed_up' => Referral::query()->where('referrer_organization_id', $organization->id)->count(),
            'rewarded' => Referral::query()->where('referrer_organization_id', $organization->id)->where('credit_cents', '>', 0)->count(),
            'credit_left' => (int) BillingCredit::withoutGlobalScopes()->where('organization_id', $organization->id)->get()->sum(fn (BillingCredit $c) => $c->remaining()),
        ];
    }
}
