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

    private ?string $default = null;

    /** @return array<string, array{label: string, description: string, mode: string, swatch: list<string>}> */
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

    /** The theme key this person sees (may be `system`). */
    public function choiceFor(?User $user): string
    {
        return $this->exists($user?->theme) ? (string) $user->theme : $this->default();
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
