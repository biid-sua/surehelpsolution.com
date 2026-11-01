<?php

namespace App\Services\System;

use App\Models\Plan;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Billing\BillingSettings;
use App\Services\FcmService;
use App\Support\DemoGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Is this installation ready to go live, and which optional features are switched on (D79)?
 * Each check says what's wrong and how to fix it. "fail" must be fixed before launch; "warn" is
 * strongly recommended; "off" is a feature that stays hidden until its settings are filled in.
 * Shown by `php artisan launch:check` and on Admin › System status. Never prints secret values.
 */
class LaunchReadiness
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public const OFF = 'off';

    /** @return list<array{group: string, label: string, status: string, detail: string, fix: string}> */
    public function checks(): array
    {
        return [
            ...$this->safely('Core', fn () => $this->core()),
            ...$this->safely('Background work', fn () => $this->background()),
            ...$this->safely('Email', fn () => $this->email()),
            ...$this->safely('Features', fn () => $this->features()),
        ];
    }

    /**
     * A section that can't be read (e.g. the database is down) shows as one failed line, not an error page.
     *
     * @param  callable(): list<array{group: string, label: string, status: string, detail: string, fix: string}>  $section
     * @return list<array{group: string, label: string, status: string, detail: string, fix: string}>
     */
    private function safely(string $group, callable $section): array
    {
        try {
            return $section();
        } catch (\Throwable $e) {
            return [['group' => $group, 'label' => 'Could not check', 'status' => self::FAIL, 'detail' => class_basename($e).': '.str($e->getMessage())->limit(120),
                'fix' => 'Usually the database or cache is unreachable: check the DB_* and CACHE_STORE settings.']];
        }
    }

    /** @return array{ok: int, warn: int, fail: int, off: int} */
    public function summary(array $checks): array
    {
        $counts = [self::OK => 0, self::WARN => 0, self::FAIL => 0, self::OFF => 0];
        foreach ($checks as $check) {
            $counts[$check['status']]++;
        }

        return $counts;
    }

    /**
     * Addresses to register with Google, Microsoft, Meta, LinkedIn, Twilio, Stripe and the app stores.
     *
     * @return array<string, list<array{label: string, url: string}>>
     */
    public function urls(): array
    {
        return [
            'Google Cloud (calendar, Business Profile, Search Console): OAuth redirect URIs' => [
                ['label' => 'Business calendars', 'url' => route('app.integrations.calendar.callback', 'google')],
                ['label' => 'Agent calendars', 'url' => route('agent.calendar.callback', 'google')],
                ['label' => 'Google Business Profile', 'url' => route('app.social.connect.callback', 'google')],
                ['label' => 'Search Console (SEO)', 'url' => route('app.seo.search-console.callback')],
                ['label' => 'Calendar push notifications (webhook)', 'url' => route('webhooks.calendar.google')],
            ],
            'Microsoft Entra (Outlook / Microsoft 365 calendars): redirect URIs' => [
                ['label' => 'Business calendars', 'url' => route('app.integrations.calendar.callback', 'microsoft')],
                ['label' => 'Agent calendars', 'url' => route('agent.calendar.callback', 'microsoft')],
                ['label' => 'Calendar change notifications (webhook)', 'url' => route('webhooks.calendar.microsoft')],
            ],
            'Meta (Facebook / Instagram)' => [
                ['label' => 'Facebook Login › Valid OAuth redirect URIs (Pages and Instagram)', 'url' => route('app.social.connect.callback', 'meta')],
                ['label' => 'Webhook callback URL (Messenger, Instagram messages)', 'url' => route('webhooks.meta')],
            ],
            'QuickBooks Online (developer.intuit.com): redirect URI' => [
                ['label' => 'Businesses connecting QuickBooks', 'url' => route('app.apps.quickbooks.callback')],
            ],
            'Jobber (Developer Center): OAuth callback URL' => [
                ['label' => 'Businesses connecting Jobber', 'url' => route('app.apps.jobber.callback')],
            ],
            'LinkedIn: authorized redirect URL' => [
                ['label' => 'LinkedIn pages', 'url' => route('app.social.connect.callback', 'linkedin')],
            ],
            'Twilio (numbers bought under Admin › Phone lines are pointed here automatically)' => [
                ['label' => 'TwiML App › Voice request URL (agents calling out)', 'url' => route('webhooks.voice.outgoing')],
                ['label' => 'TwiML App › Status callback URL', 'url' => route('webhooks.voice.status')],
                ['label' => 'Messaging Service › Incoming messages URL', 'url' => route('webhooks.sms.incoming')],
                ['label' => 'Numbers added by hand › "A call comes in"', 'url' => route('webhooks.voice.incoming')],
            ],
            'Stripe: webhook endpoint' => [
                ['label' => 'Events: checkout, invoices, payment methods', 'url' => route('webhooks.stripe')],
                ['label' => 'Connect endpoint ("events on connected accounts"): checkout.session.completed, charge.refunded, account.updated', 'url' => route('webhooks.stripe-connect')],
            ],
            'Mobile app links (must answer on the live domain)' => [
                ['label' => 'Android (Digital Asset Links)', 'url' => route('app-links.android')],
                ['label' => 'iOS (Universal Links)', 'url' => route('app-links.apple')],
            ],
        ];
    }

    /** @return list<array{group: string, label: string, status: string, detail: string, fix: string}> */
    private function core(): array
    {
        $g = 'Core';
        $url = (string) config('app.url');
        $pending = $this->pendingMigrations();

        return [
            $this->check($g, 'Production mode', config('app.env') === 'production' && ! config('app.debug'),
                'APP_ENV='.config('app.env').', APP_DEBUG='.(config('app.debug') ? 'true' : 'false'), 'Set APP_ENV=production and APP_DEBUG=false.'),
            $this->check($g, 'Encryption key', filled(config('app.key')), 'APP_KEY', 'Run php artisan key:generate once (never change it afterwards).'),
            $this->check($g, 'Site address uses HTTPS', str_starts_with($url, 'https://'), $url ?: 'APP_URL is empty',
                'Set APP_URL to the live address, e.g. https://surehelpsolution.com. Webhooks and OAuth need HTTPS.'),
            $this->check($g, 'Database', $this->databaseOk(), config('database.default'), 'Check the DB_* settings.'),
            $this->check($g, 'Database up to date', $pending === 0, $pending === null ? 'could not read' : ($pending === 0 ? 'all migrations run' : $pending.' pending'),
                'Back up the database, then run php artisan migrate --force.'),
            $this->check($g, 'Front-end build', is_file(public_path('build/manifest.json')), 'public/build/manifest.json',
                'Run npm ci && npm run build locally and upload public/build/.'),
            $this->check($g, 'Storage is writable', is_writable(storage_path('app')) && is_writable(storage_path('logs')), 'storage/', 'Make storage/ and bootstrap/cache/ writable by the web server.'),
            $this->check($g, 'PHP zip extension', extension_loaded('zip'), 'needed for data exports', 'Enable zip (cPanel › Select PHP Version › Extensions).'),
            $this->check($g, 'Secure cookies', (bool) config('session.secure'), 'SESSION_SECURE_COOKIE', 'Set SESSION_SECURE_COOKIE=true.', self::WARN),
            $this->check($g, 'Sessions in the database', config('session.driver') === 'database', 'SESSION_DRIVER='.config('session.driver'),
                'Set SESSION_DRIVER=database (the device list on Account › Security needs it).', self::WARN),
            $this->check($g, 'Log level', config('logging.channels.single.level') !== 'debug', 'LOG_LEVEL='.config('logging.channels.single.level'), 'Set LOG_LEVEL=warning.', self::WARN),
            $this->check($g, 'Mobile sign-in tokens expire', (int) config('sanctum.expiration') > 0, 'SANCTUM_TOKEN_EXPIRATION', 'Set SANCTUM_TOKEN_EXPIRATION=43200 (30 days).', self::WARN),
            $this->check($g, 'Content-Security-Policy enforced', config('csp.mode') === 'enforce', 'CSP_MODE='.config('csp.mode'),
                'Run a week with CSP_MODE=report, then set CSP_MODE=enforce.', self::WARN),
            $this->check($g, 'Agents only see assigned businesses', ! config('tenancy.auto_assign_agents'), 'TENANCY_AUTO_ASSIGN_AGENTS',
                'Set TENANCY_AUTO_ASSIGN_AGENTS=false and review Agent assignments.', self::WARN),
            $this->check($g, 'No demo data', ! DemoGuard::present(), 'Admin › Demo data', 'Remove the demo data under Admin › Demo data before real customers arrive.'),
        ];
    }

    /** @return list<array{group: string, label: string, status: string, detail: string, fix: string}> */
    private function background(): array
    {
        $g = 'Background work';
        $beat = Cache::get('scheduler:heartbeat');
        $stuck = $this->stuckJobs();
        $failed = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : 0;

        return [
            $this->check($g, 'Scheduler (cron) is running', $beat && now()->timestamp - (int) $beat < 300,
                $beat ? 'last ran '.now()->subSeconds(now()->timestamp - (int) $beat)->diffForHumans() : 'never seen',
                'Add the cron entry: * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1'),
            $this->check($g, 'Queue is being worked', $stuck === 0, $stuck === null ? 'queue is not the database' : ($stuck === 0 ? 'no waiting jobs older than 10 minutes' : $stuck.' jobs waiting over 10 minutes'),
                'The scheduler runs queue:work every minute; check the cron entry and storage/logs.'),
            $this->check($g, 'Nightly database backup', ($last = Cache::get('backup:last')) && now()->timestamp - (int) $last < 2 * 86400,
                ($last ?? null) ? 'last one '.now()->subSeconds(now()->timestamp - (int) $last)->diffForHumans() : 'none yet',
                'Runs at 03:15 with the scheduler. Run php artisan backup:database once to check it works, and set BACKUP_DISK=s3 for an off-server copy.', self::WARN),
            $this->check($g, 'Error alerts by email', filled(config('ops.alert_email')), 'Admin › Connections › Email', 'Set where server error alerts go under Admin › Connections › Email, and point an uptime monitor at /health.', self::WARN),
            $this->check($g, 'No failed jobs today', $failed === 0, $failed.' in the last 24 hours', 'See php artisan queue:failed, fix the cause, then php artisan queue:retry all.', self::WARN),
        ];
    }

    /** @return list<array{group: string, label: string, status: string, detail: string, fix: string}> */
    private function email(): array
    {
        $g = 'Email';
        $mailer = (string) config('mail.default');

        return [
            $this->check($g, 'Emails are really sent', ! in_array($mailer, ['log', 'array'], true), 'Sending: '.$mailer,
                'Fill in the mail server under Admin › Connections › Email, then press "Send me a test email".'),
            $this->check($g, 'Sender address', filled(config('mail.from.address')) && ! str_ends_with((string) config('mail.from.address'), '@example.com'),
                (string) config('mail.from.address'), 'Set the From address under Admin › Connections › Email to one on your domain (with SPF and DKIM set up).'),
            $this->check($g, 'Enquiry inbox', filled(config('mail.contact_to')), 'Admin › Connections › Email', 'Set where website enquiries go under Admin › Connections › Email.', self::WARN),
        ];
    }

    /** @return list<array{group: string, label: string, status: string, detail: string, fix: string}> */
    private function features(): array
    {
        $g = 'Features';
        $billing = app(BillingSettings::class);
        $twilio = config('telephony.twilio');
        $telephony = config('telephony.driver') === 'twilio';

        return [
            $this->feature($g, 'AI (assistant, sales chat, SEO, social writer)', app(AiProvider::class)->isConfigured(), 'Admin › Connections › AI',
                'Add a Claude or ChatGPT key under Admin › Connections › AI.'),
            $this->feature($g, 'Google Calendar sync', filled(config('calendar.providers.google.client_id')) && filled(config('calendar.providers.google.client_secret')),
                'Admin › Connections › Calendars', 'Create an OAuth client in Google Cloud, add it under Admin › Connections › Calendars, and register the redirect URLs shown there.'),
            $this->feature($g, 'Microsoft 365 / Outlook sync', filled(config('calendar.providers.microsoft.client_id')) && filled(config('calendar.providers.microsoft.client_secret')),
                'Admin › Connections › Calendars', 'Register an app in Microsoft Entra, add it under Admin › Connections › Calendars, and add the redirect URLs shown there.'),
            $this->feature($g, 'Phone calls (Twilio)', $telephony && filled($twilio['account_sid'] ?? null) && filled($twilio['auth_token'] ?? null)
                && filled($twilio['api_key'] ?? null) && filled($twilio['api_secret'] ?? null) && filled($twilio['twiml_app_sid'] ?? null),
                'Admin › Connections › Phone and texts', 'Fill in the Twilio account, API key and TwiML App under Admin › Connections › Phone and texts, and choose Twilio for calls.'),
            $this->feature($g, 'Text messages (SMS)', $telephony && (bool) config('telephony.sms'), 'Admin › Connections › Phone and texts',
                'After A2P 10DLC brand and campaign approval in Twilio, tick "Texting is on" under Admin › Connections › Phone and texts.'),
            $this->feature($g, 'Card payments (Stripe)', config('billing.gateway') === 'stripe' && filled(config('billing.stripe.secret')) && filled(config('billing.stripe.webhook_secret')),
                'Admin › Connections › Payments', 'Optional. Add the Stripe keys under Admin › Connections › Payments. Without it, invoices are paid by transfer (see docs/billing.md).'),
            $this->feature($g, 'Customer payments (Stripe Connect)', filled(config('billing.stripe.secret')) && filled(config('billing.stripe.connect_webhook_secret')),
                'Admin › Connections › Payments', 'Optional (D85). Turn on Connect in Stripe, add the Connect webhook shown under Admin › Connections › Payments, then businesses can take deposits and pay links.'),
            $this->feature($g, 'Invoice details', filled($billing->get('company_address')) && filled($billing->get('company_email'))
                && (filled($billing->get('payoneer_payment_link')) || filled($billing->get('bank_details')) || config('billing.gateway') === 'stripe'),
                'Admin › Billing › Payment settings', 'Fill in your company address, billing email and how clients pay.'),
            $this->feature($g, 'Plans', Schema::hasTable('plans') && Plan::query()->where('is_active', true)->exists(), 'Admin › Billing › Plans',
                'Create the plans clients subscribe to (keep them in step with the pricing on the website).'),
            $this->feature($g, 'Facebook, Instagram and Messenger', filled(config('social.connectors.meta.client_id')) && filled(config('social.connectors.meta.client_secret')),
                'Admin › Connections › Social networks', 'Create a Meta app, pass App Review for the page and messaging permissions, and add it under Admin › Connections.'),
            $this->feature($g, 'LinkedIn pages', filled(config('social.connectors.linkedin.client_id')), 'Admin › Connections › Social networks', 'Create a LinkedIn app with Community Management access and add it under Admin › Connections.'),
            $this->feature($g, 'Google Business Profile', (bool) config('social.connectors.google.enabled'), 'Admin › Connections › Social networks',
                'After Google approves Business Profile API access, tick it under Admin › Connections › Social networks.'),
            $this->feature($g, 'Phone app push notifications', app(FcmService::class)->isConfigured(), 'Admin › Connections › Mobile app',
                'Create a Firebase project, upload the APNs key for iOS, and paste the service account key under Admin › Connections › Mobile app.'),
            $this->feature($g, 'Mobile app in the stores', filled(config('mobile.ios.store_url')) && filled(config('mobile.android.store_url')), 'Admin › Connections › Mobile app',
                'After the store listings go live, add their links and app-link details under Admin › Connections › Mobile app.'),
        ];
    }

    /** @return array{group: string, label: string, status: string, detail: string, fix: string} */
    private function check(string $group, string $label, bool $ok, string $detail, string $fix, string $otherwise = self::FAIL): array
    {
        return ['group' => $group, 'label' => $label, 'status' => $ok ? self::OK : $otherwise, 'detail' => $detail, 'fix' => $ok ? '' : $fix];
    }

    /** @return array{group: string, label: string, status: string, detail: string, fix: string} */
    private function feature(string $group, string $label, bool $on, string $detail, string $fix): array
    {
        return $this->check($group, $label, $on, $detail, $fix, self::OFF);
    }

    private function databaseOk(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function pendingMigrations(): ?int
    {
        try {
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return count($migrator->getMigrationFiles(database_path('migrations')));
            }
            $ran = $migrator->getRepository()->getRan();

            return count(array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $ran));
        } catch (\Throwable) {
            return null;
        }
    }

    private function stuckJobs(): ?int
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return config('queue.default') === 'sync' ? 0 : null;
        }

        return (int) DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<', now()->subMinutes(10)->timestamp)->count();
    }
}
