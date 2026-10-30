<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Jobs\PullQuickBooks;
use App\Services\Apps\QuickBooks\QuickBooks;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/**
 * Connects a business's QuickBooks Online company (D110). Same state checks as calendar sync: the
 * answer must come back to the same person, for the same business, within 15 minutes.
 */
class QuickBooksController extends Controller
{
    private const SESSION_KEY = 'quickbooks_oauth';

    public function __construct(private readonly QuickBooks $quickBooks) {}

    public function redirect(Request $request, CurrentOrganization $current): RedirectResponse
    {
        abort_unless($this->quickBooks->isConfigured(), 404);

        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY, [
            'state' => $state, 'organization_id' => $current->id(), 'user_id' => $request->user()->id, 'at' => now()->timestamp,
        ]);

        return redirect()->away($this->quickBooks->authorizationUrl($state));
    }

    public function callback(Request $request, CurrentOrganization $current, Audit $audit): RedirectResponse
    {
        $pending = $request->session()->pull(self::SESSION_KEY);
        $back = redirect()->route('app.business.apps');

        $valid = is_array($pending)
            && hash_equals((string) $pending['state'], (string) $request->query('state'))
            && $pending['organization_id'] === $current->id()
            && $pending['user_id'] === $request->user()->id
            && now()->timestamp - $pending['at'] < 900;
        if (! $valid) {
            return $back->with('error', 'That connection attempt expired. Please try again.');
        }
        $realm = (string) $request->query('realmId');
        if ($request->filled('error') || ! $request->filled('code') || ! preg_match('/^\d{1,30}$/', $realm)) {
            return $back->with('error', 'QuickBooks wasn\'t connected.');
        }

        try {
            $connection = $this->quickBooks->connect($current->get(), (string) $request->query('code'), $realm, $request->user());
        } catch (\Throwable $e) {
            report($e);

            return $back->with('error', 'QuickBooks didn\'t let us connect. Please try again, signed in as an admin of the company.');
        }

        $audit->record('apps.quickbooks_connected', $connection, new: ['company' => $connection->account_name]);
        Bus::dispatchAfterResponse(new PullQuickBooks($connection->id));

        return $back->with('status', 'QuickBooks connected'.($connection->account_name ? ' to '.$connection->account_name : '').'. Your QuickBooks customers are coming in now.');
    }
}
