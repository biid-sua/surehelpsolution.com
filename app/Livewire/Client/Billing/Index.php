<?php

namespace App\Livewire\Client\Billing;

use App\Actions\Billing\ManageAddons;
use App\Actions\Billing\ReportPayment;
use App\Enums\InvoiceStatus;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Addon;
use App\Models\Invoice;
use App\Models\OrganizationAddon;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\UsageRecord;
use App\Models\User;
use App\Notifications\PlanChangeRequested;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\Entitlements;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Services\Billing\Usage;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Billing for a business (spec §29): plan, what's due and how to pay, invoices, payment history.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Billing')]
class Index extends Component
{
    use ScopedToOrganization;
    use WithPagination;

    public ?string $reporting = null;

    public string $paymentNote = '';

    public function mount(): void
    {
        $this->authorize('billing.view', $this->organization());
    }

    public function startReport(string $ulid): void
    {
        $this->authorize('billing.manage', $this->organization()); // owners; managers are view-only (docs/billing.md)
        $this->invoice($ulid);
        $this->resetValidation();
        $this->paymentNote = '';
        $this->reporting = $ulid;
    }

    public function report(ReportPayment $report): void
    {
        $this->authorize('billing.manage', $this->organization());
        $this->validate(['paymentNote' => ['required', 'string', 'max:500']], ['paymentNote.required' => 'Tell us how and when you paid (e.g. "Card via Payoneer on Oct 3").']);

        try {
            $report->handle($this->invoice((string) $this->reporting), auth()->user(), $this->paymentNote);
        } catch (ValidationException $e) {
            $this->addError('paymentNote', $e->getMessage());

            return;
        }

        $this->reporting = null;
        $this->dispatch('toast', type: 'success', message: 'Thanks! We\'ll confirm your payment shortly.');
    }

    public function requestPlan(int $planId, Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('billing.manage', $organization);
        $plan = Plan::query()->where('is_active', true)->where('is_public', true)->findOrFail($planId);

        $team = User::query()->where('role', 'admin')->where('is_active', true)->get()->filter(fn (User $u) => $u->hasPermissionIn('billing.manage'));
        Notification::send($team, new PlanChangeRequested($organization, $plan, auth()->user()));
        $audit->record('subscription.change_requested', $plan, new: ['plan' => $plan->slug], organization: $organization, label: $plan->name);

        $this->dispatch('toast', type: 'success', message: 'Request sent. We\'ll confirm the change by email.');
    }

    public function activateAddon(int $addonId, ManageAddons $addons): void
    {
        $organization = $this->organization();
        $this->authorize('billing.manage', $organization);
        $addon = Addon::query()->where('is_active', true)->findOrFail($addonId);

        try {
            $addons->activate($organization, $addon, auth()->user());
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'warning', message: collect($e->errors())->flatten()->first());

            return;
        }
        $this->dispatch('toast', type: 'success', message: "{$addon->name} is on.");
    }

    public function deactivateAddon(int $id, ManageAddons $addons): void
    {
        $organization = $this->organization();
        $this->authorize('billing.manage', $organization);
        $active = OrganizationAddon::query()->forOrganization($organization)->active()->with('addon')->findOrFail($id);
        $addons->deactivate($organization, $active, auth()->user());
        $this->dispatch('toast', type: 'success', message: "{$active->addon->name} stays on until the end of this billing period.");
    }

    private function invoice(string $ulid): Invoice
    {
        return Invoice::query()->forOrganization($this->organization())->where('ulid', $ulid)->firstOrFail();
    }

    public function render(Entitlements $entitlements, PaymentGateway $gateway, BillingSettings $settings, Usage $usage): View
    {
        $organization = $this->organization();
        $subscription = $entitlements->subscription($organization)?->loadMissing('nextPlan');
        $active = OrganizationAddon::query()->forOrganization($organization)->active()->with('addon')->get()->keyBy('addon_id');
        $outstanding = Invoice::query()->forOrganization($organization)->outstanding()->orderBy('due_at')->get();

        return view('livewire.client.billing.index', [
            'subscription' => $subscription,
            'outstanding' => $outstanding,
            'balance' => $outstanding->sum(fn (Invoice $i) => $i->balanceCents()),
            'options' => $outstanding->isNotEmpty() ? $gateway->options($outstanding->first()) : [],
            'invoices' => Invoice::query()->forOrganization($organization)->latest('issued_at')->latest('id')->paginate(12),
            'payments' => Payment::query()->forOrganization($organization)->with('invoice:id,number')->latest('received_at')->limit(10)->get(),
            'plans' => Plan::query()->where('is_active', true)->where('is_public', true)->ordered()->get(),
            'instructions' => $settings->get('payment_instructions'),
            'canManage' => auth()->user()->can('billing.manage', $organization),
            'openStatus' => InvoiceStatus::Open,
            'meter' => $subscription ? $usage->current($subscription) : null,
            'history' => UsageRecord::query()->forOrganization($organization)->latest('period_start')->limit(6)->get(),
            'addons' => Addon::query()->where('is_active', true)->ordered()->get()->concat(
                $active->pluck('addon')->filter(fn (?Addon $a) => $a && ! $a->is_active)   // retired, but this business still has it
            ),
            'activeAddons' => $active,
        ]);
    }
}
