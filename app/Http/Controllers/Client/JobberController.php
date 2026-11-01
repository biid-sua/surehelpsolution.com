<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Jobs\PullJobber;
use App\Services\Apps\Jobber\Jobber;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/**
 * Connects a business's Jobber account (D111). Same state checks as calendar sync: the answer must
 * come back to the same person, for the same business, within 15 minutes.
 */
class JobberController extends Controller
{
    private const SESSION_KEY = 'jobber_oauth';

    public function __construct(private readonly Jobber $jobber) {}

    public function redirect(Request $request, CurrentOrganization $current): RedirectResponse
    {
        abort_unless($this->jobber->isConfigured(), 404);

        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY, [
            'state' => $state, 'organization_id' => $current->id(), 'user_id' => $request->user()->id, 'at' => now()->timestamp,
        ]);

        return redirect()->away($this->jobber->authorizationUrl($state));
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
        if ($request->filled('error') || ! $request->filled('code')) {
            return $back->with('error', 'Jobber wasn\'t connected.');
        }

        try {
            $connection = $this->jobber->connect($current->get(), (string) $request->query('code'), $request->user());
        } catch (\Throwable $e) {
            report($e);

            return $back->with('error', 'Jobber didn\'t let us connect. Please try again, signed in as the account owner or an admin.');
        }

        $audit->record('apps.jobber_connected', $connection, new: ['account' => $connection->account_name]);
        Bus::dispatchAfterResponse(new PullJobber($connection->id));

        return $back->with('status', 'Jobber connected'.($connection->account_name ? ' to '.$connection->account_name : '').'. Your Jobber clients are coming in now.');
    }
}
