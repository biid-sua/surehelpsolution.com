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

    /**
     * Groups of fields. `config` is the config key it fills; `secret` fields are shown masked;
     * `type`: text (default), select (with `options`) or bool.
     *
     * @return array<string, array{label: string, help: string, fields: array<string, array<string, mixed>>}>
     */
    public static function groups(): array
    {
        return [
            'ai' => [
                'label' => 'AI',
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
                'help' => 'One Meta app (developers.facebook.com) covers Facebook Pages and the Instagram accounts linked to them.',
                'fields' => [
                    'meta_client_id' => ['label' => 'App ID', 'config' => 'social.connectors.meta.client_id'],
                    'meta_client_secret' => ['label' => 'App secret', 'config' => 'social.connectors.meta.client_secret', 'secret' => true],
                ],
            ],
            'linkedin' => [
                'label' => 'LinkedIn',
                'help' => 'An app at linkedin.com/developers with the "Share on LinkedIn" and Community Management products.',
                'fields' => [
                    'linkedin_client_id' => ['label' => 'Client ID', 'config' => 'social.connectors.linkedin.client_id'],
                    'linkedin_client_secret' => ['label' => 'Client secret', 'config' => 'social.connectors.linkedin.client_secret', 'secret' => true],
                ],
            ],
            'google' => [
                'label' => 'Google Business Profile',
                'help' => 'A Google Cloud OAuth client with Business Profile API access.',
                'fields' => [
                    'google_client_id' => ['label' => 'Client ID', 'config' => 'social.connectors.google.client_id'],
                    'google_client_secret' => ['label' => 'Client secret', 'config' => 'social.connectors.google.client_secret', 'secret' => true],
                    'google_enabled' => ['label' => 'Business Profile API access granted', 'config' => 'social.connectors.google.enabled', 'type' => 'bool'],
                ],
            ],
            'youtube' => [
                'label' => 'YouTube',
                'help' => 'A Google Cloud OAuth client with the YouTube Data API v3 turned on. Leave blank to use the Google Business Profile client.',
                'fields' => [
                    'youtube_client_id' => ['label' => 'Client ID', 'config' => 'social.connectors.youtube.client_id'],
                    'youtube_client_secret' => ['label' => 'Client secret', 'config' => 'social.connectors.youtube.client_secret', 'secret' => true],
                ],
            ],
            'x' => [
                'label' => 'X (Twitter)',
                'help' => 'An app at developer.x.com with OAuth 2.0 turned on (type: Web App). Posting needs a paid X API plan.',
                'fields' => [
                    'x_client_id' => ['label' => 'OAuth 2.0 Client ID', 'config' => 'social.connectors.x.client_id'],
                    'x_client_secret' => ['label' => 'OAuth 2.0 Client secret', 'config' => 'social.connectors.x.client_secret', 'secret' => true],
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
        foreach ($saved as $key => $value) {
            if (! isset($fields[$key]) || $value === '') {
                continue;
            }
            $type = $fields[$key]['type'] ?? 'text';
            config([$fields[$key]['config'] => $type === 'bool' ? $value === '1' : $value]);
        }
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
        $this->apply();
        if ($changed !== []) {
            // Names only: values (secrets above all) never go in the audit log.
            app(Audit::class)->record('connections.saved', null, new: ['fields' => $changed], actor: $by, label: 'Connections');
        }

        return $changed;
    }

    public function remove(string $key, User $by): void
    {
        if (PlatformSecret::query()->whereKey($key)->delete()) {
            Cache::forget(self::CACHE);
            app(Audit::class)->record('connections.removed', null, new: ['fields' => [$key]], actor: $by, label: 'Connections');
        }
        config([self::fields()[$key]['config'] => null]);
        $this->apply();
    }
}
