<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\Billing\Entitlements;
use App\Services\Social\SocialManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Connects a business's social accounts through OAuth (spec §41). Tokens are exchanged on the server
 * and stored encrypted; the browser only ever sees the network's consent screen.
 */
class SocialOAuthController extends Controller
{
    private const SESSION_KEY = 'social_oauth';

    public function __construct(private readonly SocialManager $social) {}

    public function redirect(Request $request, CurrentOrganization $current, string $connector): RedirectResponse
    {
        $adapter = $this->social->connector($connector);
        abort_unless($adapter->isConfigured(), 404);

        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'connector' => $connector,
            'organization_id' => $current->id(),
            'user_id' => $request->user()->id,
            'at' => now()->timestamp,
        ]);

        return redirect()->away($adapter->authorizationUrl($this->redirectUri($connector), $state));
    }

    public function callback(Request $request, CurrentOrganization $current, Entitlements $entitlements, Audit $audit, string $connector): RedirectResponse
    {
        $pending = $request->session()->pull(self::SESSION_KEY);
        $back = redirect()->route('app.social.accounts');

        // Forged callbacks (CSRF): the state must be the one we issued to this person, for this business, recently.
        $valid = is_array($pending)
            && hash_equals((string) $pending['state'], (string) $request->query('state'))
            && $pending['connector'] === $connector
            && $pending['organization_id'] === $current->id()
            && $pending['user_id'] === $request->user()->id
            && now()->timestamp - $pending['at'] < 900;

        if (! $valid) {
            return $back->with('error', 'That connection attempt expired. Please try again.');
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return $back->with('error', 'Nothing was connected: permission was not granted.');
        }

        $adapter = $this->social->connector($connector);

        try {
            $tokens = $adapter->exchangeCode((string) $request->query('code'), $this->redirectUri($connector));
            $found = $adapter->discover($tokens);
        } catch (\Throwable $e) {
            report($e);

            return $back->with('error', 'We couldn\'t finish connecting to '.$adapter->label().'. Please try again.');
        }

        if ($found === []) {
            return $back->with('error', match ($connector) {
                'meta' => 'No Facebook Pages were shared with SureHelp. Connect again and tick the Pages (and Instagram accounts) you want to use.',
                'google' => 'No Google Business Profile locations were found for that Google account.',
                default => 'No accounts were found to post to.',
            });
        }

        $organization = $current->get();
        $limit = $entitlements->limit($organization, 'social_accounts');
        $enabled = SocialAccount::query()->forOrganization($organization)->where('is_enabled', true)->count();
        $added = 0;

        foreach ($found as $account) {
            $existing = SocialAccount::query()->forOrganization($organization)
                ->where('network', $account->network->value)->where('external_id', $account->externalId)->first();

            // Turn new accounts on when the person clearly meant them (just one found) and the plan has room.
            $enable = $existing->is_enabled ?? (count($found) === 1 && ($limit === null || $enabled < $limit));

            $saved = SocialAccount::updateOrCreate(
                ['organization_id' => $organization->id, 'network' => $account->network->value, 'external_id' => $account->externalId],
                [
                    'name' => $account->name,
                    'handle' => $account->handle,
                    'avatar_url' => $account->avatarUrl,
                    'access_token' => $account->accessToken,
                    'refresh_token' => $account->refreshToken,
                    'token_expires_at' => $account->expiresAt,
                    'meta' => $account->meta ?: null,
                    'is_enabled' => $enable,
                    'status' => SocialAccount::STATUS_ACTIVE,
                    'last_error' => null,
                    'connected_by_user_id' => $request->user()->id,
                ],
            );
            $added += $saved->wasRecentlyCreated ? 1 : 0;
            $enabled += ($enable && ! $existing?->is_enabled) ? 1 : 0;
        }

        $audit->record('social.connected', null, new: ['connector' => $connector, 'accounts' => count($found), 'new' => $added], organization: $organization, label: $adapter->label());

        $message = $adapter->label().' connected: '.count($found).' '.Str::plural('account', count($found)).' found.';

        return $back->with('success', count($found) > 1 ? $message.' Turn on the ones you want to post to.' : $message);
    }

    private function redirectUri(string $connector): string
    {
        return route('app.social.connect.callback', $connector);
    }
}
