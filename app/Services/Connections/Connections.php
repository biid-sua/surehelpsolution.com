<?php

namespace App\Services\Connections;

use App\Models\PlatformSecret;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Admin › Connections (D104): AI providers and social network apps set up from the admin console,
 * with no code or .env change. Each field maps to a config key; a saved value overrides .env for
 * every request (applied at boot). Secrets are encrypted at rest and never sent back to the browser.
 */
class Connections
{
    private const CACHE = 'platform_secrets.v1';

    /** Tabs on the page, in order. */
    public const TABS = [
        'ai' => 'AI',
        'social' => 'Social networks',
        'phone' => 'Phone and texts',
        'email' => 'Email',
        'payments' => 'Payments',
        'calendars' => 'Calendars',
        'push' => 'Mobile app',
        'websites' => 'Websites',
    ];

    /** @var array<string, mixed> Values from .env, kept so removing a saved value goes back to them. */
    private static array $fromEnv = [];

    /**
     * Groups of fields. `config` is the config key it fills; `secret` fields are shown masked;
     * `type`: text (default), select (with `options`), bool, number, textarea or model;
     * `rules` adds validation; `fallback` is a config key used when this one ends up empty. A group
     * shows "Set up" once its `needs` fields (default: its secrets) have a value.
     *
     * @return array<string, array{label: string, tab: string, help: string, needs?: list<string>, fields: array<string, array<string, mixed>>}>
     */
    public static function groups(): array
    {
        return [
            'ai' => [
                'label' => 'AI',
                'tab' => 'ai',
                'help' => 'Claude (Anthropic) and ChatGPT (OpenAI). Connect one or both, then choose which one writes.',
                'fields' => [
                    'ai_provider' => ['label' => 'AI that writes for SureHelp features', 'config' => 'ai.provider', 'type' => 'select',
                        'options' => ['claude' => 'Claude', 'openai' => 'ChatGPT']],
                    'ai_social_provider' => ['label' => 'AI that writes SureHelp\'s own social posts', 'config' => 'ai.social_provider', 'type' => 'select',
                        'options' => ['' => 'Same as above', 'claude' => 'Claude', 'openai' => 'ChatGPT']],
                    'anthropic_api_key' => ['label' => 'Claude API key', 'config' => 'ai.anthropic.api_key', 'secret' => true, 'placeholder' => 'sk-ant-…'],
                    'anthropic_model' => ['label' => 'Claude model', 'config' => 'ai.anthropic.model', 'type' => 'model', 'provider' => 'claude'],
                    'openai_api_key' => ['label' => 'ChatGPT (OpenAI) API key', 'config' => 'ai.openai.api_key', 'secret' => true, 'placeholder' => 'sk-…'],
                    'openai_model' => ['label' => 'ChatGPT model', 'config' => 'ai.openai.model', 'type' => 'model', 'provider' => 'openai'],
                    'openai_image_model' => ['label' => 'ChatGPT picture model', 'config' => 'ai.openai.image_model', 'type' => 'model', 'provider' => 'openai', 'images' => true],
                ],
            ],
            'meta' => [
                'label' => 'Facebook and Instagram',
                'needs' => ['meta_client_id', 'meta_client_secret'],
                'tab' => 'social',
                'help' => 'One Meta app (developers.facebook.com) covers Facebook Pages and the Instagram accounts linked to them.',
                'fields' => [
                    'meta_client_id' => ['label' => 'App ID', 'config' => 'social.connectors.meta.client_id'],
                    'meta_client_secret' => ['label' => 'App secret', 'config' => 'social.connectors.meta.client_secret', 'secret' => true],
                    'meta_webhook_verify_token' => ['label' => 'Webhook verify token (for Messenger and Instagram messages)', 'config' => 'social.connectors.meta.webhook_verify_token', 'secret' => true,
                        'placeholder' => 'Any long random text; type the same in the Meta app'],
                ],
            ],
            'linkedin' => [
                'label' => 'LinkedIn',
                'tab' => 'social',
                'help' => 'An app at linkedin.com/developers with the "Share on LinkedIn" and Community Management products.',
                'fields' => [
                    'linkedin_client_id' => ['label' => 'Client ID', 'config' => 'social.connectors.linkedin.client_id'],
                    'linkedin_client_secret' => ['label' => 'Client secret', 'config' => 'social.connectors.linkedin.client_secret', 'secret' => true],
                ],
            ],
            'google' => [
                'label' => 'Google Business Profile',
                'tab' => 'social',
                'help' => 'A Google Cloud OAuth client with Business Profile API access. Leave blank to use the Google Calendar client.',
                'fields' => [
                    'google_client_id' => ['label' => 'Client ID', 'config' => 'social.connectors.google.client_id', 'fallback' => 'calendar.providers.google.client_id'],
                    'google_client_secret' => ['label' => 'Client secret', 'config' => 'social.connectors.google.client_secret', 'secret' => true, 'fallback' => 'calendar.providers.google.client_secret'],
                    'google_enabled' => ['label' => 'Business Profile API access granted', 'config' => 'social.connectors.google.enabled', 'type' => 'bool'],
                ],
            ],
            'youtube' => [
                'label' => 'YouTube',
                'tab' => 'social',
                'help' => 'A Google Cloud OAuth client with the YouTube Data API v3 turned on. Leave blank to use the Google Business Profile client.',
                'fields' => [
                    'youtube_client_id' => ['label' => 'Client ID', 'config' => 'social.connectors.youtube.client_id'],
                    'youtube_client_secret' => ['label' => 'Client secret', 'config' => 'social.connectors.youtube.client_secret', 'secret' => true],
                ],
            ],
            'x' => [
                'label' => 'X (Twitter)',
                'tab' => 'social',
                'help' => 'An app at developer.x.com with OAuth 2.0 turned on (type: Web App). Posting needs a paid X API plan.',
                'fields' => [
                    'x_client_id' => ['label' => 'OAuth 2.0 Client ID', 'config' => 'social.connectors.x.client_id'],
                    'x_client_secret' => ['label' => 'OAuth 2.0 Client secret', 'config' => 'social.connectors.x.client_secret', 'secret' => true],
                ],
            ],
            'twilio' => [
                'label' => 'Twilio',
                'needs' => ['twilio_account_sid', 'twilio_auth_token', 'twilio_api_key', 'twilio_api_secret'],
                'tab' => 'phone',
                'help' => 'Live calls, the agents\' browser phone and texts. From the Twilio Console: the Account SID and Auth Token on the home page, and an API key under Account › API keys.',
                'fields' => [
                    'telephony_driver' => ['label' => 'Calls go through', 'config' => 'telephony.driver', 'type' => 'select',
                        'options' => ['fake' => 'Demo phone (no real calls)', 'twilio' => 'Twilio (real calls)']],
                    'twilio_account_sid' => ['label' => 'Account SID', 'config' => 'telephony.twilio.account_sid', 'placeholder' => 'AC…', 'rules' => ['nullable', 'regex:/^AC[0-9a-f]{32}$/i']],
                    'twilio_auth_token' => ['label' => 'Auth Token', 'config' => 'telephony.twilio.auth_token', 'secret' => true],
                    'twilio_api_key' => ['label' => 'API key SID', 'config' => 'telephony.twilio.api_key', 'placeholder' => 'SK…', 'rules' => ['nullable', 'regex:/^SK[0-9a-f]{32}$/i']],
                    'twilio_api_secret' => ['label' => 'API key secret', 'config' => 'telephony.twilio.api_secret', 'secret' => true],
                    'twilio_twiml_app_sid' => ['label' => 'TwiML App SID (only for calls agents place)', 'config' => 'telephony.twilio.twiml_app_sid', 'placeholder' => 'AP…', 'rules' => ['nullable', 'regex:/^AP[0-9a-f]{32}$/i']],
                    'twilio_webhook_url' => ['label' => 'Public web address Twilio calls (only behind a proxy)', 'config' => 'telephony.twilio.webhook_url', 'rules' => ['nullable', 'url:https']],
                    'telephony_notify_from' => ['label' => 'SureHelp number that sends notification texts', 'config' => 'telephony.notify_from', 'placeholder' => '+15125550100', 'rules' => ['nullable', 'regex:/^\\+[1-9]\\d{7,14}$/']],
                    'telephony_sms' => ['label' => 'Texting is on (after the A2P 10DLC registration is approved)', 'config' => 'telephony.sms', 'type' => 'bool'],
                    'telephony_transcribe' => ['label' => 'Transcribe recorded calls', 'config' => 'telephony.transcribe', 'type' => 'bool'],
                ],
            ],
            'mail' => [
                'label' => 'Email sending',
                'needs' => ['mail_host', 'mail_from_address'],
                'tab' => 'email',
                'help' => 'The mail server SureHelp sends from (for example Amazon SES, Postmark, Mailgun, SendGrid or Google Workspace, through SMTP).',
                'fields' => [
                    'mail_mailer' => ['label' => 'Send email', 'config' => 'mail.default', 'type' => 'select',
                        'options' => ['smtp' => 'Through the mail server below', 'log' => 'Don\'t send (write to the log, for testing)']],
                    'mail_host' => ['label' => 'Server (host)', 'config' => 'mail.mailers.smtp.host', 'placeholder' => 'email-smtp.us-east-1.amazonaws.com'],
                    'mail_port' => ['label' => 'Port', 'config' => 'mail.mailers.smtp.port', 'type' => 'number', 'cast' => 'int', 'rules' => ['nullable', 'integer', 'between:1,65535']],
                    'mail_scheme' => ['label' => 'Security', 'config' => 'mail.mailers.smtp.scheme', 'type' => 'select',
                        'options' => ['' => 'Automatic (STARTTLS, usually port 587)', 'smtps' => 'SSL/TLS (usually port 465)']],
                    'mail_username' => ['label' => 'Username', 'config' => 'mail.mailers.smtp.username'],
                    'mail_password' => ['label' => 'Password', 'config' => 'mail.mailers.smtp.password', 'secret' => true],
                    'mail_from_address' => ['label' => 'From address', 'config' => 'mail.from.address', 'rules' => ['nullable', 'email']],
                    'mail_from_name' => ['label' => 'From name', 'config' => 'mail.from.name'],
                    'mail_contact_to' => ['label' => 'Website enquiries go to', 'config' => 'mail.contact_to', 'rules' => ['nullable', 'email']],
                    'ops_alert_email' => ['label' => 'Server error alerts go to', 'config' => 'ops.alert_email', 'rules' => ['nullable', 'email']],
                ],
            ],
            'stripe' => [
                'label' => 'Stripe',
                'needs' => ['stripe_secret', 'stripe_webhook_secret'],
                'tab' => 'payments',
                'help' => 'Card payments for SureHelp plans, and deposits businesses take from their customers (Stripe Connect). Keys are under Developers › API keys in the Stripe Dashboard.',
                'fields' => [
                    'billing_gateway' => ['label' => 'How businesses pay SureHelp', 'config' => 'billing.gateway', 'type' => 'select',
                        'options' => ['payoneer' => 'Invoices paid by transfer (staff record payments)', 'stripe' => 'Stripe (cards and automatic payments)']],
                    'stripe_secret' => ['label' => 'Secret key', 'config' => 'billing.stripe.secret', 'secret' => true, 'placeholder' => 'sk_live_… or rk_live_…'],
                    'stripe_webhook_secret' => ['label' => 'Webhook signing secret', 'config' => 'billing.stripe.webhook_secret', 'secret' => true, 'placeholder' => 'whsec_…'],
                    'stripe_connect_webhook_secret' => ['label' => 'Connect webhook signing secret', 'config' => 'billing.stripe.connect_webhook_secret', 'secret' => true, 'placeholder' => 'whsec_…'],
                    'stripe_connect_fee_percent' => ['label' => 'SureHelp\'s share of each customer payment (%)', 'config' => 'billing.stripe.connect_fee_percent', 'type' => 'number', 'cast' => 'float',
                        'rules' => ['nullable', 'numeric', 'between:0,20']],
                ],
            ],
            'google_calendar' => [
                'label' => 'Google Calendar',
                'tab' => 'calendars',
                'help' => 'A Google Cloud OAuth client (type: Web application) with the Google Calendar API turned on. It is also used for Search Console in the SEO service.',
                'fields' => [
                    'google_calendar_client_id' => ['label' => 'Client ID', 'config' => 'calendar.providers.google.client_id', 'placeholder' => '….apps.googleusercontent.com'],
                    'google_calendar_client_secret' => ['label' => 'Client secret', 'config' => 'calendar.providers.google.client_secret', 'secret' => true],
                ],
            ],
            'microsoft_calendar' => [
                'label' => 'Microsoft Outlook calendar',
                'tab' => 'calendars',
                'help' => 'An app registration in Microsoft Entra (portal.azure.com) with the Calendars.ReadWrite and offline_access permissions.',
                'fields' => [
                    'microsoft_calendar_client_id' => ['label' => 'Application (client) ID', 'config' => 'calendar.providers.microsoft.client_id'],
                    'microsoft_calendar_client_secret' => ['label' => 'Client secret value', 'config' => 'calendar.providers.microsoft.client_secret', 'secret' => true],
                    'microsoft_calendar_tenant' => ['label' => 'Accounts allowed', 'config' => 'calendar.providers.microsoft.tenant', 'type' => 'select',
                        'options' => ['common' => 'Work, school and personal accounts', 'organizations' => 'Work and school accounts only', 'consumers' => 'Personal accounts only']],
                    'calendar_push_enabled' => ['label' => 'Get changes straight away (needs https)', 'config' => 'calendar.push_enabled', 'type' => 'bool'],
                ],
            ],
            'fcm' => [
                'label' => 'Firebase (mobile app notifications)',
                'tab' => 'push',
                'help' => 'Push notifications to the SureHelp app. In the Firebase console: Project settings › Service accounts › Generate new private key, then paste the whole file here.',
                'fields' => [
                    'fcm_credentials' => ['label' => 'Service account key (JSON)', 'config' => 'fcm.credentials', 'secret' => true, 'type' => 'textarea',
                        'rules' => ['nullable', 'json', 'max:20000'], 'placeholder' => '{ "type": "service_account", … }'],
                    'fcm_project_id' => ['label' => 'Project ID (leave blank to use the one in the key)', 'config' => 'fcm.project_id'],
                ],
            ],
            'mobile' => [
                'label' => 'App stores and versions',
                'tab' => 'push',
                'needs' => ['mobile_ios_store_url', 'mobile_android_store_url'],
                'help' => 'Store links, and the versions the app compares itself with: below the minimum it must update, below the latest it suggests updating.',
                'fields' => [
                    'mobile_ios_store_url' => ['label' => 'App Store link', 'config' => 'mobile.ios.store_url', 'rules' => ['nullable', 'url:https']],
                    'mobile_ios_latest' => ['label' => 'iPhone: latest version', 'config' => 'mobile.ios.latest', 'placeholder' => '1.0.0', 'rules' => ['nullable', 'regex:/^\\d+\\.\\d+\\.\\d+$/']],
                    'mobile_ios_minimum' => ['label' => 'iPhone: oldest version allowed', 'config' => 'mobile.ios.minimum', 'placeholder' => '1.0.0', 'rules' => ['nullable', 'regex:/^\\d+\\.\\d+\\.\\d+$/']],
                    'mobile_android_store_url' => ['label' => 'Google Play link', 'config' => 'mobile.android.store_url', 'rules' => ['nullable', 'url:https']],
                    'mobile_android_latest' => ['label' => 'Android: latest version', 'config' => 'mobile.android.latest', 'placeholder' => '1.0.0', 'rules' => ['nullable', 'regex:/^\\d+\\.\\d+\\.\\d+$/']],
                    'mobile_android_minimum' => ['label' => 'Android: oldest version allowed', 'config' => 'mobile.android.minimum', 'placeholder' => '1.0.0', 'rules' => ['nullable', 'regex:/^\\d+\\.\\d+\\.\\d+$/']],
                    'mobile_android_sha256' => ['label' => 'Android signing certificate SHA-256 (for app links; separate several with commas)', 'config' => 'mobile.app_links.android_sha256', 'cast' => 'list'],
                    'mobile_ios_app_ids' => ['label' => 'iOS app IDs (TEAMID.bundle.id; separate several with commas)', 'config' => 'mobile.app_links.ios_app_ids', 'cast' => 'list'],
                ],
            ],
            'sites' => [
                'label' => 'Hosted websites',
                'needs' => ['sites_domain'],
                'tab' => 'websites',
                'help' => 'Where businesses\' SureHelp websites live, and who issues certificates for their own domains.',
                'fields' => [
                    'sites_domain' => ['label' => 'Website domain (sites live at name.this-domain)', 'config' => 'sites.domain', 'placeholder' => 'surehelp.site',
                        'rules' => ['nullable', 'regex:/^([a-z0-9-]+\\.)+[a-z]{2,}$/i']],
                    'sites_domain_driver' => ['label' => 'Certificates for businesses\' own domains', 'config' => 'sites.custom_domains.driver', 'type' => 'select',
                        'options' => ['manual' => 'This server issues them', 'cloudflare' => 'Cloudflare for SaaS']],
                    'sites_cname_target' => ['label' => 'CNAME target owners point their domain at', 'config' => 'sites.custom_domains.cname_target', 'placeholder' => 'sites.surehelp.site'],
                    'sites_ip' => ['label' => 'Server IP address (for domains without www)', 'config' => 'sites.custom_domains.ip', 'rules' => ['nullable', 'ip']],
                    'cloudflare_token' => ['label' => 'Cloudflare API token', 'config' => 'sites.custom_domains.cloudflare.token', 'secret' => true],
                    'cloudflare_zone' => ['label' => 'Cloudflare zone ID', 'config' => 'sites.custom_domains.cloudflare.zone'],
                ],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function fields(): array
    {
        return array_merge(...array_values(array_map(fn (array $g) => $g['fields'], self::groups())));
    }

    /**
     * Saved values, decrypted (cached, so this costs one cache read per request).
     *
     * @return array<string, string>
     */
    public function saved(): array
    {
        return Cache::rememberForever(self::CACHE, fn () => PlatformSecret::query()->get()->mapWithKeys(fn (PlatformSecret $s) => [$s->key => (string) $s->value])->all());
    }

    /** Fill config from the saved values (boot). Quietly does nothing before the table exists. */
    public function apply(): void
    {
        try {
            $saved = $this->saved();
        } catch (Throwable) {
            return;
        }
        $fields = self::fields();
        foreach ($fields as $key => $field) {
            if (! array_key_exists($field['config'], self::$fromEnv)) {
                self::$fromEnv[$field['config']] = config($field['config']);
            }
        }
        foreach ($saved as $key => $value) {
            if (isset($fields[$key]) && $value !== '') {
                config([$fields[$key]['config'] => self::cast($fields[$key], $value)]);
            }
        }
        foreach ($fields as $field) {
            if (isset($field['fallback']) && blank(config($field['config']))) {
                config([$field['config'] => config($field['fallback'])]);
            }
        }
    }

    /** @param  array<string, mixed>  $field */
    private static function cast(array $field, string $value): mixed
    {
        return match (($field['type'] ?? 'text') === 'bool' ? 'bool' : ($field['cast'] ?? 'string')) {
            'bool' => $value === '1',
            'int' => (int) $value,
            'float' => (float) $value,
            'list' => array_values(array_filter(array_map('trim', explode(',', $value)))),
            default => $value,
        };
    }

    /** The value in use now (saved or from .env). */
    public function current(string $key): mixed
    {
        return config(self::fields()[$key]['config'] ?? '');
    }

    public function isSaved(string $key): bool
    {
        return array_key_exists($key, $this->saved());
    }

    /** "••••3f9a": enough to recognise a key without showing it. */
    public function masked(string $key): ?string
    {
        $value = (string) $this->current($key);

        return $value === '' ? null : '••••'.substr($value, -4);
    }

    /**
     * Save fields. Blank secrets keep what's there; other blanks remove the saved value (back to .env).
     *
     * @param  array<string, mixed>  $values
     * @return list<string> keys that changed
     */
    public function save(array $values, User $by): array
    {
        $fields = self::fields();
        $changed = [];
        foreach ($values as $key => $value) {
            $field = $fields[$key] ?? null;
            if (! $field) {
                continue;
            }
            $value = ($field['type'] ?? 'text') === 'bool' ? ($value ? '1' : '0') : trim((string) $value);
            if ($value === '' && ($field['secret'] ?? false)) {
                continue;
            }
            $before = $this->saved()[$key] ?? null;
            if ($value === '' && ($field['type'] ?? 'text') !== 'select') {
                if ($before !== null) {
                    PlatformSecret::query()->whereKey($key)->delete();
                    $changed[] = $key;
                }

                continue;
            }
            if ($before !== $value) {
                PlatformSecret::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by->id]);
                $changed[] = $key;
            }
            Cache::forget(self::CACHE);
        }
        Cache::forget(self::CACHE);
        $this->restoreEnv();
        $this->apply();
        if ($changed !== []) {
            // Names only: values (secrets above all) never go in the audit log.
            app(Audit::class)->record('connections.saved', null, new: ['fields' => $changed], actor: $by, label: 'Connections');
            $this->restartWorkers();
        }

        return $changed;
    }

    public function remove(string $key, User $by): void
    {
        if (PlatformSecret::query()->whereKey($key)->delete()) {
            Cache::forget(self::CACHE);
            app(Audit::class)->record('connections.removed', null, new: ['fields' => [$key]], actor: $by, label: 'Connections');
            $this->restartWorkers();
        }
        $this->restoreEnv();
        $this->apply();
    }

    /** Back to the .env values, before the saved ones are applied again. */
    private function restoreEnv(): void
    {
        foreach (self::$fromEnv as $config => $value) {
            config([$config => $value]);
        }
    }

    /**
     * Queue workers read settings once when they start; this asks them to finish the job in hand
     * and restart (the process manager starts them again), so new keys are used straight away.
     */
    private function restartWorkers(): void
    {
        Cache::forever('illuminate:queue:restart', now()->getTimestamp());
    }
}
