<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Organization;
use App\Services\Business\Branding;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The unsubscribe link in reminder and campaign emails (D116): signed, one click, no sign-in. The
 * customer still gets messages about their own appointments, just not reminders or offers.
 */
class UnsubscribeController extends Controller
{
    public function __invoke(Request $request, string $customer, Branding $branding): View
    {
        $found = Customer::withoutGlobalScopes()->where('ulid', $customer)->firstOrFail();
        if (! $found->marketing_opt_out_at) {
            $found->forceFill(['marketing_opt_out_at' => now()])->save();
        }

        return view('marketing.unsubscribed', ['brand' => $branding->for(Organization::query()->findOrFail($found->organization_id))]);
    }
}
