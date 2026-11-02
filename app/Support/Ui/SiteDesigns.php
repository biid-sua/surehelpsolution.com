<?php

namespace App\Support\Ui;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\URL;

/**
 * The home page's layout (D121). Classic is the page the site launched with; Modern is the redesign,
 * kept ready until an administrator switches to it in Admin › Appearance. Until then it can only be
 * seen through a signed preview link, which search engines are told not to index.
 */
class SiteDesigns
{
    public const SETTING = 'ui.site_design';

    public const DEFAULT = 'classic';

    /** How long a preview link works. */
    public const PREVIEW_DAYS = 14;

    private ?string $current = null;

    /** @return array<string, array{label: string, description: string, view: string}> */
    public function all(): array
    {
        return [
            'classic' => [
                'label' => 'Classic',
                'description' => 'The current dark home page. The colour picker and the website colours below apply to it.',
                'view' => 'home',
            ],
            'modern' => [
                'label' => 'Modern',
                'description' => 'The new light design: a cleaner layout, live call preview, savings calculator and a simpler contact form. It has its own colours.',
                'view' => 'home-modern',
            ],
        ];
    }

    public function exists(?string $key): bool
    {
        return $key !== null && array_key_exists($key, $this->all());
    }

    /** The design every visitor sees on the home page. */
    public function current(): string
    {
        if ($this->current === null) {
            $saved = rescue(fn () => PlatformSetting::get(self::SETTING), null, report: false);
            $this->current = is_string($saved) && $this->exists($saved) ? $saved : self::DEFAULT;
        }

        return $this->current;
    }

    public function set(string $key): void
    {
        PlatformSetting::put(self::SETTING, $key);
        $this->current = $key;
    }

    public function view(string $key): string
    {
        return $this->all()[$key]['view'] ?? 'home';
    }

    /** A link anyone holding it can open for {@see PREVIEW_DAYS} days, to look at a design before it goes live. */
    public function previewUrl(string $key): string
    {
        return URL::temporarySignedRoute('home.preview', now()->addDays(self::PREVIEW_DAYS), ['design' => $key]);
    }
}
