<?php

namespace App\Http\Controllers\Client;

use App\Exceptions\BillingUnavailable;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\Stripe\CardPayments;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Pay by card" on an invoice (D64): off to Stripe Checkout, back to Billing when done. */
class InvoicePaymentController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current, CardPayments $payments, string $invoice): RedirectResponse
    {
        $organization = $current->get();
        abort_unless($payments->enabled(), 404);
        abort_unless($request->user()->can('billing.manage', $organization), 403); // owners pay; managers can only view billing
        $model = Invoice::query()->forOrganization($organization)->where('ulid', $invoice)->firstOrFail();

        try {
            return redirect()->away($payments->checkoutUrl($model, $request->user(), $request->boolean('save')));
        } catch (BillingUnavailable $e) {
            return redirect()->route('app.billing')->with('status', $e->getMessage());
        }
    }
}
