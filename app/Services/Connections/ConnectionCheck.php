<?php

namespace App\Services\Connections;

use App\Models\User;
use App\Services\FcmService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The "Test" buttons under Admin › Connections (D105): one harmless, read-only request to each
 * service with the keys given, so a typo shows up before anything depends on it. Nothing is
 * charged, sent or changed, except the test email, which goes to the person pressing the button.
 */
class ConnectionCheck
{
    /** Services that have a test. */
    public const GROUPS = ['twilio', 'mail', 'stripe', 'fcm', 'sites'];

    /**
     * @param  callable(string): string  $value  the typed value, else the one in use
     * @return array{ok: bool, message: string}
     */
    public function run(string $group, callable $value, User $by): array
    {
        try {
            return match ($group) {
                'twilio' => $this->twilio($value('twilio_account_sid'), $value('twilio_auth_token'), $value('twilio_api_key'), $value('twilio_api_secret')),
                'stripe' => $this->stripe($value('stripe_secret')),
                'sites' => $this->cloudflare($value('cloudflare_token'), $value('cloudflare_zone')),
                'mail' => $this->mail($by),
                'fcm' => $this->fcm(),
                default => ['ok' => false, 'message' => 'There is no test for this one.'],
            };
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => 'The test couldn\'t reach the service. Please try again.'];
        }
    }

    /** @return array{ok: bool, message: string} */
    private function twilio(string $sid, string $token, string $apiKey, string $apiSecret): array
    {
        if ($sid === '' || $token === '') {
            return ['ok' => false, 'message' => 'Enter the Account SID and Auth Token first.'];
        }
        $account = Http::withBasicAuth($sid, $token)->timeout(15)->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");
        if (! $account->successful()) {
            return ['ok' => false, 'message' => 'Twilio refused the Account SID and Auth Token: '.$this->reason($account, 'message')];
        }
        $message = 'Twilio accepted them: account "'.$account->json('friendly_name').'" ('.$account->json('status').').';
        if ($apiKey !== '' && $apiSecret !== '') {
            $key = Http::withBasicAuth($apiKey, $apiSecret)->timeout(15)->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");
            if (! $key->successful()) {
                return ['ok' => false, 'message' => $message.' But the API key and secret were refused, so the browser phone won\'t work.'];
            }
            $message .= ' The API key works too.';
        }

        return ['ok' => true, 'message' => $message];
    }

    /** @return array{ok: bool, message: string} */
    private function stripe(string $secret): array
    {
        if ($secret === '') {
            return ['ok' => false, 'message' => 'Enter the secret key first.'];
        }
        $balance = Http::withToken($secret)->timeout(15)->get('https://api.stripe.com/v1/balance');
        if (! $balance->successful()) {
            return ['ok' => false, 'message' => 'Stripe refused the key: '.$this->reason($balance, 'error.message')];
        }

        return ['ok' => true, 'message' => 'Stripe accepted the key ('.($balance->json('livemode') ? 'live mode: real payments' : 'test mode: no real money').').'];
    }

    /** @return array{ok: bool, message: string} */
    private function cloudflare(string $token, string $zone): array
    {
        if ($token === '') {
            return ['ok' => false, 'message' => 'Enter the Cloudflare API token first.'];
        }
        $verify = Http::withToken($token)->timeout(15)->get('https://api.cloudflare.com/client/v4/user/tokens/verify');
        if (! $verify->successful() || $verify->json('result.status') !== 'active') {
            return ['ok' => false, 'message' => 'Cloudflare refused the token: '.$this->reason($verify, 'errors.0.message')];
        }
        if ($zone === '') {
            return ['ok' => true, 'message' => 'Cloudflare accepted the token. Add the zone ID too.'];
        }
        $found = Http::withToken($token)->timeout(15)->get("https://api.cloudflare.com/client/v4/zones/{$zone}");
        if (! $found->successful()) {
            return ['ok' => false, 'message' => 'The token works, but it can\'t open that zone: '.$this->reason($found, 'errors.0.message')];
        }

        return ['ok' => true, 'message' => 'Cloudflare accepted the token for '.$found->json('result.name').'.'];
    }

    /** Uses the saved settings: that's what every other email will use. @return array{ok: bool, message: string} */
    private function mail(User $by): array
    {
        Mail::raw("This is a test from Admin › Connections. Email sending works.\n\nSureHelp", function ($message) use ($by) {
            $message->to($by->email)->subject('SureHelp test email');
        });

        return ['ok' => true, 'message' => config('mail.default') === 'log'
            ? 'Email is set not to send, so the test was written to the log.'
            : 'Test email sent to '.$by->email.'. If it doesn\'t arrive in a few minutes, check the spam folder and the settings.'];
    }

    /** Uses the saved key: Firebase must give us a sign-in token for it. @return array{ok: bool, message: string} */
    private function fcm(): array
    {
        $fcm = app(FcmService::class);
        if (! $fcm->isConfigured()) {
            return ['ok' => false, 'message' => 'Save the service account key first.'];
        }

        return $fcm->canSignIn()
            ? ['ok' => true, 'message' => 'Firebase accepted the service account. The app can get notifications.']
            : ['ok' => false, 'message' => 'Firebase refused the service account. Generate a new key and paste it again.'];
    }

    private function reason(Response $response, string $path): string
    {
        return (string) str((string) ($response->json($path) ?: 'error '.$response->status()))->limit(160);
    }
}
