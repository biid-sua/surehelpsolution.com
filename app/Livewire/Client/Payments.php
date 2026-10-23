<?php

namespace App\Livewire\Client;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Customer;
use App\Models\PaymentRequest;
use App\Services\Billing\FeatureAccess;
use App\Services\Payments\CustomerPayments;
use App\Support\Audit\Audit;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Payments (D85): connect Stripe, ask customers to pay by text or email, take deposits for online
 * bookings, and see what came in.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Payments')]
class Payments extends Component
{
    use ScopedToOrganization, WithPagination;

    public const FILTERS = ['open' => 'Waiting', 'paid' => 'Paid', 'all' => 'All'];

    #[Url]
    public string $filter = 'open';

    public bool $composing = false;

    public string $search = '';

    public string $customer = '';   // ulid

    public string $amount = '';

    public string $description = '';

    /** @var list<string> */
    public array $channels = ['sms', 'email'];

    public bool $depositEnabled = false;

    public string $depositAmount = '';

    public function mount(CustomerPayments $payments): void
    {
        $organization = $this->organization();
        $this->authorize('payments.view', $organization);
        $account = $payments->account($organization);
        if ($account && request()->boolean('connected')) {
            $payments->refresh($account);
        }
        $this->depositEnabled = (bool) $account?->deposit_enabled;
        $this->depositAmount = $account?->deposit_cents ? Money::toInput($account->deposit_cents) : '';
        if (request()->boolean('new') && $payments->ready($organization)) {
            $this->compose((string) request()->query('customer', ''));
        }
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function compose(string $customer = ''): void
    {
        $this->authorize('payments.request', $this->organization());
        $this->reset(['search', 'amount', 'description']);
        $this->channels = ['sms', 'email'];
        $this->customer = $customer !== '' && $this->findCustomer($customer) ? $customer : '';
        $this->composing = true;
        $this->resetValidation();
    }

    public function pick(string $ulid): void
    {
        $this->customer = $this->findCustomer($ulid) ? $ulid : '';
        $this->search = '';
    }

    public function send(CustomerPayments $payments): void
    {
        $organization = $this->organization();
        $this->authorize('payments.request', $organization);
        abort_unless($payments->ready($organization), 403);
        $data = $this->validate([
            'customer' => ['required', 'string'],
            'amount' => ['required', 'string', 'max:20'],
            'description' => ['required', 'string', 'max:200'],
            'channels' => ['array', 'min:1'],
            'channels.*' => ['in:sms,email'],
        ], ['customer.required' => 'Choose the customer.', 'channels.min' => 'Send it by text, email or both.']);
        $customer = $this->findCustomer($data['customer']);
        if (! $customer) {
            $this->addError('customer', 'Choose the customer.');

            return;
        }
        $cents = $this->cents($data['amount'], 'amount');
        if ($cents === null) {
            return;
        }

        $request = $payments->request($organization, ['amount_cents' => $cents, 'description' => $data['description'], 'customer_id' => $customer->id], auth()->user());
        $sent = $payments->send($request, $data['channels']);
        $this->composing = false;
        $this->filter = 'open';
        $this->dispatch('toast', type: $sent ? 'success' : 'warning', message: $sent
            ? 'Payment request for '.$request->amount().' sent by '.implode(' and ', array_map(fn ($c) => $c === 'sms' ? 'text' : 'email', $sent)).'.'
            : 'Created, but it couldn\'t be sent: the customer has no mobile number or email we can use. Copy the link instead.');
    }

    public function resend(string $ulid, CustomerPayments $payments): void
    {
        $this->authorize('payments.request', $this->organization());
        $request = $this->find($ulid);
        abort_unless($request->status === PaymentRequest::OPEN, 422);
        $sent = $payments->send($request, ['sms', 'email']);
        $this->dispatch('toast', type: $sent ? 'success' : 'warning', message: $sent ? 'Sent again.' : 'Couldn\'t send it: no mobile number or email we can use.');
    }

    public function cancel(string $ulid, CustomerPayments $payments): void
    {
        $this->authorize('payments.manage', $this->organization());
        $payments->cancel($this->find($ulid), auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Payment request cancelled. The link no longer works.');
    }

    public function saveDeposit(Audit $audit, CustomerPayments $payments): void
    {
        $organization = $this->organization();
        $this->authorize('payments.manage', $organization);
        $account = $payments->account($organization) ?? abort(404);
        $cents = 0;
        if ($this->depositEnabled) {
            $cents = $this->cents($this->depositAmount, 'depositAmount');
            if ($cents === null) {
                return;
            }
        }
        $account->forceFill(['deposit_enabled' => $this->depositEnabled, 'deposit_cents' => $cents])->save();
        $audit->changes('payments.settings', $account, ['deposit_enabled', 'deposit_cents']);
        $this->dispatch('toast', type: 'success', message: $this->depositEnabled ? 'Online bookings now ask for a '.Money::format($cents).' deposit.' : 'Online bookings no longer ask for a deposit.');
    }

    private function cents(string $input, string $field): ?int
    {
        try {
            $cents = Money::parse($input);
        } catch (\InvalidArgumentException) {
            $this->addError($field, 'Enter an amount like 75 or 49.50.');

            return null;
        }
        if ($cents < 100 || $cents > 2_000_000) {
            $this->addError($field, 'Between $1 and $20,000.');

            return null;
        }

        return $cents;
    }

    private function find(string $ulid): PaymentRequest
    {
        return PaymentRequest::query()->forOrganization($this->organization())->where('ulid', $ulid)->firstOrFail();
    }

    private function findCustomer(string $ulid): ?Customer
    {
        return Customer::query()->forOrganization($this->organization())->where('ulid', $ulid)->first();
    }

    public function render(CustomerPayments $payments, FeatureAccess $features): View
    {
        $organization = $this->organization();
        $search = trim($this->search);

        return view('livewire.client.payments', [
            'allowed' => $features->allows($organization, 'customer_payments'),
            'available' => $payments->isAvailable(),
            'account' => $payments->account($organization),
            'ready' => $payments->ready($organization),
            'summary' => $payments->summary($organization, now($organization->timezoneOrDefault())->startOfMonth()->utc()),
            'requests' => PaymentRequest::query()->forOrganization($organization)->with(['customer:id,ulid,first_name,last_name', 'appointment:id,ulid'])
                ->when($this->filter !== 'all', fn ($q) => $q->where('status', $this->filter))->latest()->paginate(15),
            'chosen' => $this->customer !== '' ? $this->findCustomer($this->customer) : null,
            'matches' => $this->composing && mb_strlen($search) >= 2 ? Customer::query()->forOrganization($organization)
                ->where(fn ($q) => $q->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                ->orderBy('first_name')->limit(8)->get() : collect(),
            'filters' => self::FILTERS,
            'canManage' => auth()->user()->can('payments.manage', $organization),
            'canRequest' => auth()->user()->can('payments.request', $organization),
            'timezone' => $organization->timezoneOrDefault(),
        ]);
    }
}
