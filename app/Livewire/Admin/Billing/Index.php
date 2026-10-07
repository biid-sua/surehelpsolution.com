<?php

namespace App\Livewire\Admin\Billing;

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\ManageSubscription;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\VoidInvoice;
use App\Actions\Notifications\NotifyOrganization;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Notifications\InvoiceIssued;
use App\Services\Billing\BillingSettings;
use App\Support\Audit\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * SureHelp's own billing desk (spec §28): plans, subscriptions, invoices, recording Payoneer payments, settings.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Billing')]
class Index extends Component
{
    use PlatformAdminOnly;
    use WithPagination;

    public const TABS = ['invoices' => 'Invoices', 'subscriptions' => 'Subscriptions', 'plans' => 'Plans', 'addons' => 'Add-ons', 'features' => 'Feature access', 'settings' => 'Payment settings'];

    #[Url(except: 'invoices')]
    public string $tab = 'invoices';

    #[Url(except: 'attention')]
    public string $filter = 'attention';

    #[Url(as: 'invoice', except: '')]
    public string $selected = '';

    /** @var array<string, string> */
    public array $payment = [];

    public string $paymentLink = '';

    public string $voidReason = '';

    /** @var array<string, string> one-off invoice */
    public array $oneOff = ['organization_id' => '', 'description' => '', 'amount' => ''];

    /** @var array<string, string> */
    public array $subscribe = ['organization_id' => '', 'plan_id' => '', 'start_on' => '', 'trial' => '1'];

    /** @var array<string, mixed> */
    public array $plan = [];

    public ?int $editingPlan = null;

    /** @var array<string, mixed> */
    public array $settings = [];

    public function mount(BillingSettings $settings): void
    {
        $this->authorize('billing.view');
        $this->tab = array_key_exists($this->tab, self::TABS) ? $this->tab : 'invoices';
        $this->settings = $settings->all();
        $this->subscribe['start_on'] = now()->toDateString();
        $this->resetPlanForm();
        if ($this->selected !== '') {
            $this->select($this->selected);
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'filter'], true)) {
            $this->resetPage();
        }
    }

    // Invoices ─────────────────────────────────────────────────────────────────

    public function select(string $ulid): void
    {
        $invoice = Invoice::withoutGlobalScopes()->where('ulid', $ulid)->firstOrFail();
        $this->selected = $invoice->ulid;
        $this->paymentLink = (string) $invoice->payment_url;
        $this->voidReason = '';
        $this->payment = [
            'amount' => Money::toInput($invoice->balanceCents()),
            'method' => PaymentMethod::PayoneerCard->value,
            'reference' => '',
            'received_on' => now()->toDateString(),
            'notes' => '',
        ];
        $this->resetValidation();
    }

    public function savePaymentLink(Audit $audit): void
    {
        $this->authorize('billing.manage');
        $this->validate(['paymentLink' => ['nullable', 'url:https', 'max:2000']], ['paymentLink.url' => 'Paste the full https:// link from Payoneer.']);
        $invoice = $this->current();
        $invoice->forceFill(['payment_url' => $this->paymentLink ?: null])->save();
        $audit->changes('invoice.payment_link_set', $invoice, ['payment_url']);
        $this->dispatch('toast', type: 'success', message: 'Payment link saved. It shows on the invoice and the client\'s billing page.');
    }

    public function resend(NotifyOrganization $notify): void
    {
        $this->authorize('billing.manage');
        $invoice = $this->current();
        $recipients = $notify->handle($invoice->organization, new InvoiceIssued($invoice), 'billing.view');
        $this->dispatch('toast', type: 'success', message: 'Invoice sent to '.$recipients->count().' '.Str::plural('person', $recipients->count()).'.');
    }

    public function recordPayment(RecordPayment $record): void
    {
        $this->authorize('billing.manage');
        $this->resetValidation();
        $this->validate([
            'payment.amount' => ['required', 'string'],
            'payment.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment.reference' => ['nullable', 'string', 'max:190'],
            'payment.received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment.notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['payment.reference' => 'Payoneer transaction ID', 'payment.received_on' => 'date received']);

        try {
            $cents = Money::parse($this->payment['amount']);
            $record->handle($this->current(), $cents, PaymentMethod::from($this->payment['method']), $this->payment['reference'], auth()->user(),
                CarbonImmutable::parse($this->payment['received_on'].' 12:00'), $this->payment['notes'] ?: null);
        } catch (\InvalidArgumentException) {
            $this->addError('payment.amount', 'Enter an amount like 299 or 299.00.');

            return;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('payment.'.$field, $messages[0]);
            }

            return;
        }

        $this->select($this->selected);
        $this->dispatch('toast', type: 'success', message: 'Payment recorded and a receipt sent.');
    }

    public function void(VoidInvoice $void): void
    {
        $this->authorize('billing.manage');
        $this->resetValidation();
        try {
            $void->handle($this->current(), auth()->user(), $this->voidReason);
        } catch (ValidationException $e) {
            $this->addError('voidReason', collect($e->errors())->flatten()->first());

            return;
        }
        $this->dispatch('toast', type: 'success', message: 'Invoice voided.');
    }

    public function issueOneOff(IssueInvoice $issue): void
    {
        $this->authorize('billing.manage');
        $this->resetValidation();
        $this->validate([
            'oneOff.organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'oneOff.description' => ['required', 'string', 'max:200'],
            'oneOff.amount' => ['required', 'string'],
        ], [], ['oneOff.organization_id' => 'business', 'oneOff.description' => 'description', 'oneOff.amount' => 'amount']);

        try {
            $cents = Money::parse($this->oneOff['amount']);
        } catch (\InvalidArgumentException) {
            $this->addError('oneOff.amount', 'Enter an amount like 150 or 150.00.');

            return;
        }

        $invoice = $issue->issue(Organization::findOrFail((int) $this->oneOff['organization_id']),
            [['description' => trim($this->oneOff['description']), 'quantity' => 1, 'unit_cents' => $cents]], 'USD', auth()->user());
        $this->oneOff = ['organization_id' => '', 'description' => '', 'amount' => ''];
        $this->select($invoice->ulid);
        $this->dispatch('toast', type: 'success', message: "Invoice {$invoice->number} issued and emailed.");
    }

    private function current(): Invoice
    {
        return Invoice::withoutGlobalScopes()->with('organization')->where('ulid', $this->selected)->firstOrFail();
    }

    // Subscriptions ────────────────────────────────────────────────────────────

    public function startSubscription(ManageSubscription $manage): void
    {
        $this->authorize('billing.manage');
        $this->resetValidation();
        $this->validate([
            'subscribe.organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'subscribe.plan_id' => ['required', 'integer', 'exists:plans,id'],
            'subscribe.start_on' => ['required', 'date_format:Y-m-d'],
        ], [], ['subscribe.organization_id' => 'business', 'subscribe.plan_id' => 'plan', 'subscribe.start_on' => 'start date']);

        try {
            $manage->subscribe(Organization::findOrFail((int) $this->subscribe['organization_id']), Plan::findOrFail((int) $this->subscribe['plan_id']),
                auth()->user(), CarbonImmutable::parse($this->subscribe['start_on']), (bool) $this->subscribe['trial']);
        } catch (ValidationException $e) {
            $this->addError('subscribe.organization_id', collect($e->errors())->flatten()->first());

            return;
        }

        $this->subscribe = ['organization_id' => '', 'plan_id' => '', 'start_on' => now()->toDateString(), 'trial' => '1'];
        $this->dispatch('toast', type: 'success', message: 'Subscription started.');
    }

    public function changePlan(int $subscriptionId, string $planId, ManageSubscription $manage): void
    {
        $this->authorize('billing.manage');
        if ($planId === '') {
            return;
        }
        $manage->changePlan(Subscription::withoutGlobalScopes()->findOrFail($subscriptionId), Plan::findOrFail((int) $planId), auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Plan change saved.');
    }

    public function cancelSubscription(int $subscriptionId, bool $now, ManageSubscription $manage): void
    {
        $this->authorize('billing.manage');
        $manage->cancel(Subscription::withoutGlobalScopes()->findOrFail($subscriptionId), auth()->user(), ! $now);
        $this->dispatch('toast', type: 'success', message: $now ? 'Subscription cancelled.' : 'It will end at the end of the current period.');
    }

    public function resumeSubscription(int $subscriptionId, ManageSubscription $manage): void
    {
        $this->authorize('billing.manage');
        $manage->resume(Subscription::withoutGlobalScopes()->findOrFail($subscriptionId), auth()->user());
    }

    // Plans ────────────────────────────────────────────────────────────────────

    private function resetPlanForm(): void
    {
        $this->editingPlan = null;
        $this->plan = ['name' => '', 'price' => '', 'interval' => 'month', 'trial_days' => '0', 'description' => '', 'features' => '', 'is_public' => true, 'is_active' => true,
            'calls' => '', 'extra_call' => '', 'team_members' => '', 'calendars' => ''];
    }

    public function editPlan(int $id): void
    {
        $plan = Plan::findOrFail($id);
        $this->editingPlan = $plan->id;
        $this->plan = [
            'name' => $plan->name, 'price' => Money::toInput($plan->price_cents), 'interval' => $plan->interval, 'trial_days' => (string) $plan->trial_days,
            'description' => (string) $plan->description, 'features' => implode(', ', $plan->features ?? []), 'is_public' => $plan->is_public, 'is_active' => $plan->is_active,
            'calls' => (string) ($plan->limits['calls'] ?? ''), 'extra_call' => isset($plan->limits['extra_call_cents']) ? Money::toInput($plan->limits['extra_call_cents']) : '',
            'team_members' => (string) ($plan->limits['team_members'] ?? ''), 'calendars' => (string) ($plan->limits['calendars'] ?? ''),
        ];
        $this->resetValidation();
    }

    public function cancelPlanEdit(): void
    {
        $this->resetPlanForm();
        $this->resetValidation();
    }

    public function savePlan(Audit $audit): void
    {
        $this->authorize('billing.manage');
        $this->validate([
            'plan.name' => ['required', 'string', 'max:100'],
            'plan.price' => ['required', 'string'],
            'plan.interval' => ['required', Rule::in(array_keys(Plan::INTERVALS))],
            'plan.trial_days' => ['required', 'integer', 'min:0', 'max:90'],
            'plan.description' => ['nullable', 'string', 'max:2000'],
            'plan.features' => ['nullable', 'string', 'max:1000'],
            'plan.calls' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'plan.extra_call' => ['nullable', 'string', 'max:20'],
            'plan.team_members' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'plan.calendars' => ['nullable', 'integer', 'min:0', 'max:10'],
        ], [], ['plan.name' => 'name', 'plan.price' => 'price', 'plan.trial_days' => 'trial days', 'plan.calls' => 'calls included',
            'plan.team_members' => 'team members', 'plan.calendars' => 'calendars']);

        try {
            $extraCall = filled($this->plan['extra_call']) ? Money::parse((string) $this->plan['extra_call']) : null;
        } catch (\InvalidArgumentException) {
            $this->addError('plan.extra_call', 'Enter a price like 1.50.');

            return;
        }

        try {
            $cents = Money::parse((string) $this->plan['price']);
        } catch (\InvalidArgumentException) {
            $this->addError('plan.price', 'Enter a price like 299 or 299.00.');

            return;
        }

        $values = [
            'name' => trim($this->plan['name']),
            'price_cents' => $cents,
            'interval' => $this->plan['interval'],
            'trial_days' => (int) $this->plan['trial_days'],
            'description' => $this->plan['description'] ?: null,
            'features' => array_values(array_filter(array_map('trim', explode(',', (string) $this->plan['features'])))) ?: null,
            'is_public' => (bool) $this->plan['is_public'],
            'is_active' => (bool) $this->plan['is_active'],
            'limits' => array_filter([
                'calls' => filled($this->plan['calls']) ? (int) $this->plan['calls'] : null,
                'extra_call_cents' => $extraCall,
                'team_members' => filled($this->plan['team_members']) ? (int) $this->plan['team_members'] : null,
                'calendars' => filled($this->plan['calendars']) ? (int) $this->plan['calendars'] : null,
            ], fn ($v) => $v !== null) ?: null,
        ];

        if ($this->editingPlan) {
            $plan = Plan::findOrFail($this->editingPlan);
            $plan->fill($values)->save();
            $audit->changes('plan.updated', $plan, ['name', 'price_cents', 'interval', 'trial_days', 'is_public', 'is_active', 'limits']);
            $message = 'Plan saved. Existing customers move to the new price at their next renewal.';
        } else {
            $slug = Str::slug($values['name']);
            $plan = Plan::create($values + ['slug' => Plan::where('slug', $slug)->exists() ? $slug.'-'.Str::lower(Str::random(4)) : $slug, 'currency' => 'USD']);
            $audit->record('plan.created', $plan, new: ['name' => $plan->name, 'price_cents' => $plan->price_cents, 'interval' => $plan->interval], label: $plan->name);
            $message = 'Plan created.';
        }

        $this->resetPlanForm();
        $this->dispatch('toast', type: 'success', message: $message);
    }

    // Settings ─────────────────────────────────────────────────────────────────

    public function saveSettings(BillingSettings $settings, Audit $audit): void
    {
        $this->authorize('billing.manage');
        $this->validate([
            'settings.company_name' => ['required', 'string', 'max:150'],
            'settings.company_address' => ['nullable', 'string', 'max:500'],
            'settings.company_email' => ['nullable', 'email', 'max:190'],
            'settings.tax_id' => ['nullable', 'string', 'max:60'],
            'settings.payoneer_email' => ['nullable', 'email', 'max:190'],
            'settings.payoneer_payment_link' => ['nullable', 'url:https', 'max:2000'],
            'settings.bank_details' => ['nullable', 'string', 'max:2000'],
            'settings.payment_instructions' => ['nullable', 'string', 'max:500'],
            'settings.due_days' => ['required', 'integer', 'min:0', 'max:60'],
        ], [], ['settings.payoneer_payment_link' => 'Payoneer payment link', 'settings.due_days' => 'days to pay']);

        $settings->save($this->settings);
        $audit->record('billing.settings_updated', null, new: ['keys' => array_keys(BillingSettings::KEYS)]);
        $this->dispatch('toast', type: 'success', message: 'Payment settings saved.');
    }

    public function render(): View
    {
        $invoices = Invoice::withoutGlobalScopes()->with('organization:id,ulid,name')
            ->tap(fn (Builder $q) => match ($this->filter) {
                'open' => $q->where('status', InvoiceStatus::Open->value),
                'overdue' => $q->where('status', InvoiceStatus::Open->value)->where('due_at', '<', now()),
                'paid' => $q->where('status', InvoiceStatus::Paid->value),
                'void' => $q->where('status', InvoiceStatus::Void->value),
                'all' => $q,
                // Needs a person: reported as paid, overdue, or no way to pay yet.
                default => $q->where('status', InvoiceStatus::Open->value)->where(fn (Builder $w) => $w->whereNotNull('client_reported_paid_at')
                    ->orWhere('due_at', '<', now())
                    ->orWhere(fn (Builder $n) => $n->whereNull('payment_url')->when(filled($this->settings['payoneer_payment_link'] ?? null), fn ($x) => $x->whereRaw('1 = 0')))),
            })
            ->orderByRaw('client_reported_paid_at IS NULL')->latest('issued_at')->latest('id')
            ->paginate(20, pageName: 'invoicesPage');

        $current = Subscription::withoutGlobalScopes()->current()->with(['organization:id,ulid,name', 'plan', 'nextPlan'])->get();

        return view('livewire.admin.billing.index', [
            'tabs' => self::TABS,
            'invoices' => $invoices,
            'selectedInvoice' => $this->selected !== '' ? Invoice::withoutGlobalScopes()->with(['organization', 'items', 'payments.recordedBy', 'subscription.plan'])->where('ulid', $this->selected)->first() : null,
            'metrics' => [
                'mrr' => $current->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])->sum(fn (Subscription $s) => $s->interval === 'year' ? intdiv($s->price_cents, 12) : $s->price_cents),
                'outstanding' => (int) Invoice::withoutGlobalScopes()->where('status', InvoiceStatus::Open->value)->selectRaw('COALESCE(SUM(total_cents - amount_paid_cents), 0) as owed')->value('owed'),
                'overdue' => Invoice::withoutGlobalScopes()->where('status', InvoiceStatus::Open->value)->where('due_at', '<', now())->count(),
                'collected' => (int) Payment::withoutGlobalScopes()->where('received_at', '>=', now()->startOfMonth())->sum('amount_cents'),
                'reported' => Invoice::withoutGlobalScopes()->where('status', InvoiceStatus::Open->value)->whereNotNull('client_reported_paid_at')->count(),
            ],
            'subscriptions' => $current->sortBy(fn (Subscription $s) => $s->organization->name ?? ''),
            'plans' => Plan::query()->ordered()->withCount(['subscriptions' => fn ($q) => $q->where('status', '!=', 'cancelled')])->get(),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
            'unsubscribed' => Organization::query()->whereNotIn('id', $current->pluck('organization_id'))->orderBy('name')->get(['id', 'name']),
            'methods' => PaymentMethod::cases(),
            'canManage' => auth()->user()->can('billing.manage'),
        ]);
    }
}
