<?php

namespace App\Services\Payments;

use App\Actions\Customers\RecordTimelineEvent;
use App\Actions\Notifications\NotifyOrganization;
use App\Enums\TimelineEventType;
use App\Exceptions\BillingUnavailable;
use App\Models\Appointment;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Notifications\CustomerEmail;
use App\Notifications\CustomerPaid;
use App\Services\Billing\FeatureAccess;
use App\Services\Billing\Stripe\StripeApi;
use App\Services\Business\Branding;
use App\Services\Documents\JobDocuments;
use App\Services\Integrations\Webhooks;
use App\Services\Telephony\Texting;
use App\Support\Audit\Audit;
use App\Support\CustomerLocale;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;

/**
 * Deposits and pay-by-text (D85). Each business connects its own Stripe account (a Standard account
 * through Stripe Connect); customers pay on Stripe Checkout into that account, and Stripe tells us when
 * they have. SureHelp never holds the money and never sees card details.
 */
class CustomerPayments
{
    public function __construct(
        private readonly StripeApi $stripe,
        private readonly FeatureAccess $features,
        private readonly Texting $texting,
        private readonly NotifyOrganization $notify,
        private readonly RecordTimelineEvent $timeline,
        private readonly Branding $branding,
        private readonly Audit $audit,
    ) {}

    /** SureHelp's Stripe platform account is set up for Connect. */
    public function isAvailable(): bool
    {
        return (string) config('billing.stripe.secret') !== '' && (string) config('billing.stripe.connect_webhook_secret') !== '';
    }

    public function account(Organization $organization): ?PaymentAccount
    {
        return PaymentAccount::withoutGlobalScopes()->where('organization_id', $organization->id)->first();
    }

    /** The business can take payments now. */
    public function ready(Organization $organization): bool
    {
        return $this->isAvailable() && $this->features->allows($organization, 'customer_payments') && (bool) $this->account($organization)?->isReady();
    }

    /**
     * Where to send the owner to connect (or finish connecting) Stripe.
     *
     * @throws BillingUnavailable
     */
    public function onboardingUrl(Organization $organization, User $by): string
    {
        $account = $this->account($organization);
        if (! $account) {
            $profile = BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->first();
            $created = $this->stripe->post('accounts', array_filter([
                'type' => 'standard',
                'email' => $profile?->email ?: $by->email,
                'business_profile[name]' => $profile?->display_name ?: $organization->name,
                'business_profile[url]' => $profile?->website,
                'metadata[organization]' => $organization->ulid,
            ]), 'connect-'.$organization->ulid);
            $account = PaymentAccount::create(['organization_id' => $organization->id, 'stripe_account_id' => (string) $created['id'],
                'currency' => strtolower((string) ($created['default_currency'] ?? 'usd'))]);
            $this->audit->record('payments.connect_started', $account, organization: $organization, label: 'Stripe');
        }
        $link = $this->stripe->post('account_links', [
            'account' => $account->stripe_account_id,
            'type' => 'account_onboarding',
            'refresh_url' => route('app.payments.connect'),
            'return_url' => route('app.payments', ['connected' => 1]),
        ]);

        return (string) $link['url'];
    }

    /** Ask Stripe how the account is doing (after onboarding, and on account.updated). */
    public function refresh(PaymentAccount $account, ?array $data = null): PaymentAccount
    {
        try {
            $data ??= $this->stripe->get('accounts/'.$account->stripe_account_id);
        } catch (BillingUnavailable $e) {
            report($e);

            return $account;
        }
        $ready = (bool) ($data['charges_enabled'] ?? false);
        $account->forceFill([
            'charges_enabled' => $ready,
            'details_submitted' => (bool) ($data['details_submitted'] ?? false),
            'currency' => strtolower((string) ($data['default_currency'] ?? $account->currency)),
            'connected_at' => $ready ? ($account->connected_at ?? now()) : $account->connected_at,
        ])->save();

        return $account;
    }

    /**
     * @param  array{amount_cents: int, description: string, kind?: string, customer_id?: ?int, appointment_id?: ?int}  $data
     */
    public function request(Organization $organization, array $data, ?User $by = null): PaymentRequest
    {
        $account = $this->account($organization);
        $percent = max(0.0, min(20.0, (float) config('billing.stripe.connect_fee_percent', 0)));

        $request = PaymentRequest::create([
            'organization_id' => $organization->id,
            'customer_id' => $data['customer_id'] ?? null,
            'appointment_id' => $data['appointment_id'] ?? null,
            'kind' => $data['kind'] ?? 'payment',
            'description' => mb_substr(trim($data['description']), 0, 200),
            'amount_cents' => $data['amount_cents'],
            'currency' => $account->currency ?? 'usd',
            'fee_cents' => (int) round($data['amount_cents'] * $percent / 100),
            'created_by_user_id' => $by?->id,
        ]);
        $this->audit->record('payments.requested', $request, new: ['amount' => $request->amount(), 'kind' => $request->kind], organization: $organization, actor: $by,
            label: $request->description);

        return $request;
    }

    /**
     * Send the link by text and/or email. Returns how it went out.
     *
     * @param  list<string>  $channels  "sms", "email"
     * @return list<string>
     */
    public function send(PaymentRequest $request, array $channels): array
    {
        $organization = Organization::query()->findOrFail($request->organization_id);
        $customer = $request->customer;
        $business = $this->branding->for($organization)['name'];
        $sent = [];
        if (! $customer) {
            return $sent;
        }
        $l = CustomerLocale::forMessages($organization);   // D92
        $what = $request->kind === 'deposit' ? __('a deposit of :amount', ['amount' => $request->amount()], $l) : __('a payment of :amount', ['amount' => $request->amount()], $l);
        $first = $customer->first_name ?: __('there', [], $l);

        if (in_array('sms', $channels, true) && $customer->phone_e164 && $this->texting->enabled() && $this->texting->lineFor($organization)) {
            $text = __('Hi :name, :business has sent you a request for :what (:description). Pay securely: :link',
                ['name' => $first, 'business' => $business, 'what' => $what, 'description' => $request->description, 'link' => $request->link()], $l);
            $message = $this->texting->sendAutomatic($organization, $customer->phone_e164, $text, 'payment');
            if ($message && $message->status !== 'failed') {
                $sent[] = 'sms';
            }
        }
        if (in_array('email', $channels, true) && filter_var($customer->email, FILTER_VALIDATE_EMAIL) !== false) {
            $replyTo = BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->value('email');
            Notification::route('mail', $customer->email)->notify(new CustomerEmail(
                $request->kind === 'deposit' ? __('Your deposit for :business', ['business' => $business], $l) : __('Payment request from :business', ['business' => $business], $l),
                __('Hi :name,', ['name' => $first], $l)."\n\n".__(':business has sent you a request for :what:', ['business' => $business, 'what' => $what], $l)."\n".$request->description
                    ."\n\n".__('You can pay securely by card with the button below. The payment goes straight to :business.', ['business' => $business], $l),
                $business, $replyTo ?: null, $request->link(), __('Pay :amount', ['amount' => $request->amount()], $l), organizationId: $organization->id,
            ));
            $sent[] = 'email';
        }
        if ($sent !== []) {
            $request->forceFill(['sent_via' => array_values(array_unique([...($request->sent_via ?? []), ...$sent]))])->save();
            $this->timeline->handle($customer, $sent === ['email'] ? TimelineEventType::EmailSent : TimelineEventType::SmsSent,
                'Payment request sent: '.$request->amount(), $request->description, $request->appointment, ['payment' => $request->ulid]);
        }

        return $sent;
    }

    /**
     * A Stripe Checkout page for this request on the business's account (made again once it expires).
     *
     * @throws BillingUnavailable
     */
    public function checkoutUrl(PaymentRequest $request): string
    {
        if ($request->checkout_url && $request->checkout_expires_at?->isAfter(now()->addMinutes(5))) {
            return $request->checkout_url;
        }
        $organization = Organization::query()->findOrFail($request->organization_id);
        $account = $this->account($organization);
        if (! $account || ! $this->ready($organization)) {
            throw new BillingUnavailable($this->branding->for($organization)['name'].' can\'t take card payments right now.');
        }
        $expires = now()->addHours(23);
        $session = $this->stripe->post('checkout/sessions', array_filter([
            'mode' => 'payment',
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => $request->currency,
            'line_items[0][price_data][unit_amount]' => $request->amount_cents,
            'line_items[0][price_data][product_data][name]' => $request->description,
            'customer_email' => filter_var($request->customer?->email, FILTER_VALIDATE_EMAIL) ? $request->customer->email : null,
            'client_reference_id' => $request->ulid,
            'metadata[payment_request]' => $request->ulid,
            'payment_intent_data[metadata][payment_request]' => $request->ulid,
            'payment_intent_data[application_fee_amount]' => $request->fee_cents ?: null,
            'success_url' => route('pay.show', ['token' => $request->token, 'done' => 1]),
            'cancel_url' => route('pay.show', $request->token),
            'expires_at' => $expires->timestamp,
        ], fn ($v) => $v !== null), 'checkout-'.$request->ulid.'-'.now()->format('YmdH'), $account->stripe_account_id);

        $request->forceFill(['checkout_session_id' => (string) $session['id'], 'checkout_url' => (string) $session['url'], 'checkout_expires_at' => $expires])->save();

        return (string) $session['url'];
    }

    /**
     * Stripe says a Checkout session was paid. The amount and the account must match ours.
     *
     * @param  array<string, mixed>  $session
     */
    public function completed(array $session, string $account): void
    {
        if (($session['payment_status'] ?? null) !== 'paid') {
            return;
        }
        $request = PaymentRequest::withoutGlobalScopes()->where('ulid', (string) ($session['metadata']['payment_request'] ?? ''))->first();
        $owner = $request ? PaymentAccount::withoutGlobalScopes()->where('organization_id', $request->organization_id)->value('stripe_account_id') : null;
        if (! $request || $owner !== $account || (int) ($session['amount_total'] ?? 0) !== $request->amount_cents || $request->status === PaymentRequest::PAID) {
            return;
        }
        $request->forceFill(['status' => PaymentRequest::PAID, 'paid_at' => now(), 'payment_intent_id' => $session['payment_intent'] ?? null])->save();

        $organization = Organization::query()->findOrFail($request->organization_id);
        if ($request->customer) {
            $this->timeline->handle($request->customer, TimelineEventType::NoteAdded, 'Paid '.$request->amount(), $request->description, $request->appointment, ['payment' => $request->ulid]);
        }
        $this->notify->handle($organization, new CustomerPaid($request), 'payments.view');
        app(JobDocuments::class)->paidOnline($request);   // an invoice paid by card (D115)
        app(Webhooks::class)->dispatch('payment.paid', $request);
        $this->audit->record('payments.paid', $request, new: ['amount' => $request->amount()], organization: $organization, label: $request->description);
    }

    /** @param array<string, mixed> $charge */
    public function refunded(array $charge, string $account): void
    {
        $intent = (string) ($charge['payment_intent'] ?? '');
        $request = $intent !== '' ? PaymentRequest::withoutGlobalScopes()->where('payment_intent_id', $intent)->first() : null;
        if (! $request || ! ($charge['refunded'] ?? false)
            || PaymentAccount::withoutGlobalScopes()->where('organization_id', $request->organization_id)->value('stripe_account_id') !== $account) {
            return;
        }
        $request->forceFill(['status' => PaymentRequest::REFUNDED])->save();
    }

    public function cancel(PaymentRequest $request, User $by): void
    {
        if ($request->status !== PaymentRequest::OPEN) {
            return;
        }
        $request->forceFill(['status' => PaymentRequest::CANCELLED])->save();
        $account = PaymentAccount::withoutGlobalScopes()->where('organization_id', $request->organization_id)->value('stripe_account_id');
        if ($request->checkout_session_id && $request->checkout_expires_at?->isFuture() && $account) {
            try {
                $this->stripe->post('checkout/sessions/'.$request->checkout_session_id.'/expire', [], null, $account);
            } catch (BillingUnavailable) {
                // Already paid or expired: the webhook tells us which.
            }
        }
        $this->audit->record('payments.cancelled', $request, organization: Organization::query()->find($request->organization_id), actor: $by, label: $request->description);
    }

    /**
     * A booking made online: ask for the deposit when the business takes one. Returns the request.
     */
    public function depositFor(Appointment $appointment): ?PaymentRequest
    {
        $organization = Organization::query()->findOrFail($appointment->organization_id);
        $account = $this->account($organization);
        $customer = $appointment->customer_id ? Customer::withoutGlobalScopes()->find($appointment->customer_id) : null;
        if (! $customer || ! $account?->deposit_enabled || $account->deposit_cents < 100 || ! $this->ready($organization)) {
            return null;
        }
        $l = CustomerLocale::forMessages($organization);
        $when = $appointment->starts_at->setTimezone($organization->timezoneOrDefault())->locale($l)->translatedFormat('D, M j');
        $request = $this->request($organization, [
            'kind' => 'deposit', 'amount_cents' => $account->deposit_cents, 'customer_id' => $customer->id, 'appointment_id' => $appointment->id,
            'description' => __('Deposit for :what on :when', ['what' => $appointment->title ?: __('your appointment', [], $l), 'when' => $when], $l),
        ]);
        $this->send($request, ['email', 'sms']);

        return $request;
    }

    /** @return array{collected: int, paid: int, open: int, open_cents: int} */
    public function summary(Organization $organization, CarbonInterface $since): array
    {
        $all = PaymentRequest::withoutGlobalScopes()->where('organization_id', $organization->id);
        $paid = (clone $all)->where('status', PaymentRequest::PAID)->where('paid_at', '>=', $since);
        $open = (clone $all)->where('status', PaymentRequest::OPEN);

        return [
            'collected' => (int) (clone $paid)->sum('amount_cents'),
            'paid' => (clone $paid)->count(),
            'open' => (clone $open)->count(),
            'open_cents' => (int) (clone $open)->sum('amount_cents'),
        ];
    }

    public static function money(int $cents, string $currency = 'usd'): string
    {
        return Money::format($cents, strtoupper($currency));
    }
}
