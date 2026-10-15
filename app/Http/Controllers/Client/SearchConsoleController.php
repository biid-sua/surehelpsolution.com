<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Jobs\PullSearchConsole;
use App\Models\SearchConsoleConnection;
use App\Services\Calendar\Providers\GoogleCalendarProvider;
use App\Services\Seo\SearchConsole;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/**
 * Connects a business's Google Search Console, read-only (D57). Same state checks as calendar sync.
 */
class SearchConsoleController extends Controller
{
    private const SESSION_KEY = 'search_console_oauth';

    public function __construct(private readonly SearchConsole $console) {}

    public function redirect(Request $request, CurrentOrganization $current): RedirectResponse
    {
        abort_unless($this->console->isConfigured(), 404);

        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY, [
            'state' => $state, 'organization_id' => $current->id(), 'user_id' => $request->user()->id, 'at' => now()->timestamp,
        ]);

        return redirect()->away($this->console->authorizationUrl($state));
    }

    public function callback(Request $request, CurrentOrganization $current, Audit $audit): RedirectResponse
    {
        $pending = $request->session()->pull(self::SESSION_KEY);
        $back = redirect()->route('app.seo', ['tab' => 'settings']);

        $valid = is_array($pending)
            && hash_equals((string) $pending['state'], (string) $request->query('state'))
            && $pending['organization_id'] === $current->id()
            && $pending['user_id'] === $request->user()->id
            && now()->timestamp - $pending['at'] < 900;
        if (! $valid) {
            return $back->with('error', 'That connection attempt expired. Please try again.');
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return $back->with('error', 'Google Search Console wasn\'t connected.');
        }

        try {
            $connection = $this->console->connect($current->get(), (string) $request->query('code'), $request->user());
        } catch (\Throwable $e) {
            report($e);

            return $back->with('error', 'Google didn\'t let us connect. Check that you use Search Console with this Google account, then try again.');
        }

        $audit->record('seo.search_console_connected', $connection, new: ['account' => $connection->account_email, 'site' => $connection->site_url]);
        Bus::dispatchAfterResponse(new PullSearchConsole($connection->id));

        return $back->with('status', $connection->site_url
            ? 'Search Console connected. Your search statistics are being fetched.'
            : 'Search Console connected. Choose which of your sites to follow.');
    }

    public function disconnect(CurrentOrganization $current, Audit $audit): RedirectResponse
    {
        $connection = SearchConsoleConnection::query()->forOrganization($current->get())->first();
        if ($connection) {
            rescue(fn () => app(GoogleCalendarProvider::class)->revoke($connection->refresh_token ?: $connection->access_token), report: false);
            $audit->record('seo.search_console_disconnected', $connection, old: ['account' => $connection->account_email]);
            $connection->delete();
        }

        return redirect()->route('app.seo', ['tab' => 'settings'])->with('status', 'Search Console disconnected. The statistics already fetched are kept.');
    }
}
