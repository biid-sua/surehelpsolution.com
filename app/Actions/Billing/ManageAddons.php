<?php

namespace App\Actions\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Addon;
use App\Models\Organization;
use App\Models\OrganizationAddon;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turning add-ons on and off (task.md ADD-02, ADD-04).
 *
 * On: the price is locked; the rest of the current period is invoiced now, pro rata (free during a
 * trial); renewal invoices include it from then on. Off: it stays on until the period ends, then
 * stops being billed. Turning it back on before then simply keeps it.
 */
class ManageAddons
{
    public function __construct(
        private readonly IssueInvoice $invoices,
        private readonly Entitlements $entitlements,
        private readonly Audit $audit,
    ) {}

    public function activate(Organization $organization, Addon $addon, User $actor): OrganizationAddon
    {
        $subscription = $this->entitlements->subscription($organization);
        if (! $subscription) {
            throw ValidationException::withMessages(['addon' => 'Choose a plan first. Add-ons are billed with your subscription.']);
        }
        if (! $addon->is_active) {
            throw ValidationException::withMessages(['addon' => 'This add-on is no longer offered.']);
        }

        $existing = OrganizationAddon::withoutGlobalScopes()->forOrganization($organization)->active()->where('addon_id', $addon->id)->first();
        if ($existing) {
            if ($existing->cancel_at_period_end) {
                $existing->update(['cancel_at_period_end' => false]);
                $this->audit->record('addon.kept', $existing, organization: $organization, actor: $actor, label: $addon->name);
            }

            return $existing;
        }

        $active = DB::transaction(function () use ($organization, $addon, $actor) {
            $active = OrganizationAddon::create([
                'organization_id' => $organization->id, 'addon_id' => $addon->id, 'status' => OrganizationAddon::ACTIVE,
                'price_cents' => $addon->price_cents, 'started_on' => now()->toDateString(), 'activated_by' => $actor->id,
            ]);
            $this->audit->record('addon.activated', $active, new: ['price_cents' => $addon->price_cents], organization: $organization, actor: $actor, label: $addon->name);

            return $active;
        });

        if ($subscription->status !== SubscriptionStatus::Trialing) {
            $start = CarbonImmutable::parse($subscription->current_period_start->toDateString());
            $end = CarbonImmutable::parse($subscription->current_period_end->toDateString());
            $today = CarbonImmutable::today();
            $cents = (int) round($addon->price_cents * $this->monthlyShare($subscription->interval) * max(0, $today->diffInDays($end)) / max(1, $start->diffInDays($end)));
            if ($cents > 0) {
                $this->invoices->issue($organization, [[
                    'description' => "{$addon->name} add-on · ".$today->format('M j').' – '.$end->subDay()->format('M j, Y').' (rest of this period)',
                    'quantity' => 1, 'unit_cents' => $cents,
                ]], $addon->currency, $actor);
            }
        }

        $this->entitlements->forget($organization);

        return $active;
    }

    public function deactivate(Organization $organization, OrganizationAddon $active, User $actor): void
    {
        abort_unless($active->organization_id === $organization->id && $active->status === OrganizationAddon::ACTIVE, 404);
        $active->update(['cancel_at_period_end' => true]);
        $this->audit->record('addon.cancelled', $active, organization: $organization, actor: $actor, label: $active->addon->name);
    }

    /**
     * Invoice lines for the add-ons billed in a new period, after ending those switched off.
     *
     * @return list<array{description: string, quantity: int, unit_cents: int}>
     */
    public function renew(Organization|int $organization, string $interval, CarbonImmutable $periodStart, string $label): array
    {
        OrganizationAddon::withoutGlobalScopes()->forOrganization($organization)->active()->where('cancel_at_period_end', true)
            ->update(['status' => OrganizationAddon::ENDED, 'ended_on' => $periodStart->toDateString(), 'cancel_at_period_end' => false]);

        $share = $this->monthlyShare($interval);

        return OrganizationAddon::withoutGlobalScopes()->forOrganization($organization)->active()->with('addon')->get()
            ->map(fn (OrganizationAddon $a) => [
                'description' => "{$a->addon->name} add-on · {$label}",
                'quantity' => 1,
                'unit_cents' => (int) round($a->price_cents * $share),
            ])->values()->all();
    }

    /** Add-ons are priced per month; a yearly subscription pays twelve months at a time. */
    private function monthlyShare(string $interval): float
    {
        return $interval === 'year' ? 12.0 : 1.0;
    }

    /** Everything off when a subscription ends. */
    public function endAll(Organization|int $organization, CarbonImmutable $on): void
    {
        OrganizationAddon::withoutGlobalScopes()->forOrganization($organization)->active()
            ->update(['status' => OrganizationAddon::ENDED, 'ended_on' => $on->toDateString(), 'cancel_at_period_end' => false]);
    }
}
