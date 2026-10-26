<?php

namespace App\Support\Ui;

use App\Models\PlatformSetting;
use App\Models\User;

/**
 * Colour themes (D59). What a person sees: their own choice, else the default an administrator
 * picked, else Midnight. Choosing a theme is the person's decision: changing the default never
 * overrides it; only an explicit, audited reset by an administrator does.
 */
class Themes
{
    public const DEFAULT_SETTING = 'ui.default_theme';

    public const SITE_DEFAULT_SETTING = 'ui.site_default_theme';

    public const SITE_PICKER_SETTING = 'ui.site_theme_picker';

    /** The public website's base palette: the CSS without a `data-site-theme` attribute. */
    public const SITE_BASE = 'indigo';

    private ?string $default = null;

    private ?string $siteDefault = null;

    private ?bool $sitePicker = null;

    /** @return array<string, array{label: string, description: string, mode: string, swatch: list<string>, colors?: array<string, string>}> */
    public function portal(): array
    {
        return config('themes.portal');
    }

    public function exists(?string $key): bool
    {
        return $key !== null && array_key_exists($key, $this->portal());
    }

    /** The default for everyone who hasn't picked their own. */
    public function default(): string
    {
        if ($this->default === null) {
            $saved = rescue(fn () => PlatformSetting::get(self::DEFAULT_SETTING), null, report: false);
            $this->default = $this->exists(is_string($saved) ? $saved : null) ? $saved : (string) config('themes.default', 'midnight');
        }

        return $this->default;
    }

    public function setDefault(string $key): void
    {
        PlatformSetting::put(self::DEFAULT_SETTING, $key);
        $this->default = $key;
    }

    /** @return array<string, array{label: string, swatch: list<string>}> the public website's themes */
    public function site(): array
    {
        return config('themes.site');
    }

    /** The public website's colour theme for every visitor who hasn't picked their own. */
    public function siteDefault(): string
    {
        if ($this->siteDefault === null) {
            $saved = rescue(fn () => PlatformSetting::get(self::SITE_DEFAULT_SETTING), null, report: false);
            $this->siteDefault = is_string($saved) && array_key_exists($saved, $this->site()) ? $saved : self::SITE_BASE;
        }

        return $this->siteDefault;
    }

    /** Whether visitors see the colour picker on the public website (on unless an administrator hid it). */
    public function sitePickerVisible(): bool
    {
        if ($this->sitePicker === null) {
            $saved = rescue(fn () => PlatformSetting::get(self::SITE_PICKER_SETTING), null, report: false);
            $this->sitePicker = $saved === null ? true : (bool) $saved;
        }

        return $this->sitePicker;
    }

    public function setSite(string $default, bool $pickerVisible): void
    {
        PlatformSetting::put(self::SITE_DEFAULT_SETTING, $default);
        PlatformSetting::put(self::SITE_PICKER_SETTING, $pickerVisible);
        [$this->siteDefault, $this->sitePicker] = [$default, $pickerVisible];
    }

    /** The theme key this person sees (may be `system`). */
    public function choiceFor(?User $user): string
    {
        return $this->exists($user?->theme) ? (string) $user->theme : $this->default();
    }

    /**
     * Save a person's own choice; `default` clears it so they follow the default again.
     * Callers validate the key against {@see keys()}.
     */
    public function choose(User $user, string $key): void
    {
        $own = $key === 'default' ? null : $key;
        $user->forceFill(['theme' => $own, 'theme_chosen_at' => $own ? now() : null])->save();
    }

    /** @return list<string> every key a person may send, including `default`. */
    public function keys(): array
    {
        return [...array_keys($this->portal()), 'default'];
    }

    /**
     * What a person sees, for the mobile app (login, GET /user, PUT /user/theme).
     *
     * @return array{key: string, own: bool, default: string}
     */
    public function payload(User $user): array
    {
        return ['key' => $this->choiceFor($user), 'own' => $this->exists($user->theme), 'default' => $this->default()];
    }

    /**
     * Every theme with its colours, for the mobile app (GET /themes). `system` has no colours of
     * its own: the app shows `resolves_to.light` or `resolves_to.dark` by the device setting.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        return collect($this->portal())->map(fn (array $t, string $key) => array_filter([
            'key' => $key,
            'label' => $t['label'],
            'description' => $t['description'],
            'mode' => $t['mode'],
            'colors' => $t['colors'] ?? null,
            'resolves_to' => $key === 'system' ? ['light' => 'light', 'dark' => 'midnight'] : null,
        ], fn ($v) => $v !== null))->values()->all();
    }

    /**
     * Attributes for the <html> element. `system` is first drawn as Midnight and switched by the
     * boot script before the page paints.
     */
    public function htmlAttributes(?User $user): string
    {
        $choice = $this->choiceFor($user);
        $theme = $choice === 'system' ? 'midnight' : $choice;
        $mode = $this->portal()[$theme]['mode'] ?? 'dark';

        return sprintf('data-theme="%s" data-theme-choice="%s" style="color-scheme: %s"', e($theme), e($choice), $mode === 'light' ? 'light' : 'dark');
    }
}
