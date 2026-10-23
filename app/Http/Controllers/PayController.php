<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingUnavailable;
use App\Models\Organization;
use App\Models\PaymentRequest;
use App\Services\Business\Branding;
use App\Services\Payments\CustomerPayments;
use App\Support\CustomerLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The page a customer opens from a payment link (D85): what it's for and how much, then Stripe Checkout.
 */
class PayController extends Controller
{
    public function show(Request $request, string $token, Branding $branding): View
    {
        $payment = $this->find($token);
        CustomerLocale::apply($payment->organization_id);   // D92

        return view('pay.show', [
            'payment' => $payment,
            'brand' => $branding->for(Organization::query()->findOrFail($payment->organization_id)),
            'returning' => $request->boolean('done'),
            'error' => session('pay_error'),
        ]);
    }

    public function checkout(string $token, CustomerPayments $payments): RedirectResponse
    {
        $payment = $this->find($token);
        if ($payment->status !== PaymentRequest::OPEN) {
            return redirect()->route('pay.show', $token);
        }
        try {
            return redirect()->away($payments->checkoutUrl($payment));
        } catch (BillingUnavailable $e) {
            return redirect()->route('pay.show', $token)->with('pay_error', $e->getMessage());
        }
    }

    private function find(string $token): PaymentRequest
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{32}$/', $token) === 1, 404);

        return PaymentRequest::withoutGlobalScopes()->with('customer')->where('token', $token)->firstOrFail();
    }
}
