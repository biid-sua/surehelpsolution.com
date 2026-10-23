<?php

namespace App\Http\Controllers\Client;

use App\Exceptions\BillingUnavailable;
use App\Http\Controllers\Controller;
use App\Services\Payments\CustomerPayments;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sends the owner to Stripe to connect (or finish connecting) their account (D85).
 */
class PaymentsConnectController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current, CustomerPayments $payments): RedirectResponse
    {
        $organization = $current->get() ?? abort(404);
        abort_unless($payments->isAvailable(), 404);
        try {
            return redirect()->away($payments->onboardingUrl($organization, $request->user()));
        } catch (BillingUnavailable $e) {
            report($e);

            return redirect()->route('app.payments')->with('error', 'Stripe couldn\'t be reached: '.$e->getMessage());
        }
    }
}
