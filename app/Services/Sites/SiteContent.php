<?php

namespace App\Services\Sites;

use Illuminate\Support\Str;

/**
 * The shape of a hosted website (D99): a theme and up to 8 pages of sections. Every section has the
 * same fields (heading, text, button, items of title and text), so the AI edits data, never HTML, and
 * the templates decide how each type looks. Everything is plain text; the page escapes it.
 *
 *   {theme: {accent: "#1d4ed8", style: "bold"},
 *    pages: [{slug: "", title: "Home", seo_title, seo_description, sections: [{id, type, heading, text, button, items: [{title, text}]}]}]}
 */
class SiteContent
{
    /** Section types and what their fields mean, for the editor and the AI. */
    public const TYPES = [
        'hero' => 'The top banner: heading, text, and a button label (the button books or calls).',
        'services' => 'What the business offers: items are services (title = name, text = short description).',
        'text' => 'A heading and a paragraph or two, e.g. about us or how it works.',
        'reviews' => 'Customer quotes: items (title = customer name, text = what they said).',
        'faq' => 'Questions and answers: items (title = question, text = answer).',
        'hours' => 'Opening hours, filled in live from the business\'s hours: heading and an optional note in text.',
        'area' => 'Where the business works: heading, text, and items (title = town or area).',
        'contact' => 'Phone, email and address, filled in live: heading and text.',
        'cta' => 'A closing call to action: heading, text and a button label.',
    ];

    public const STYLES = ['bold', 'clean', 'warm'];

    private const LIMITS = ['heading' => 120, 'text' => 1500, 'button' => 40, 'item_title' => 120, 'item_text' => 600, 'items' => 12];

    /**
     * Cleans content from the builder or the AI: unknown keys dropped, text trimmed and capped, slugs
     * made safe and unique, a home page always first. Never throws; bad parts are left out.
     *
     * @param  array<string, mixed>  $content
     * @return array{theme: array{accent: string, style: string}, pages: list<array<string, mixed>>}
     */
    public static function normalize(array $content): array
    {
        $theme = (array) ($content['theme'] ?? []);
        $accent = is_string($theme['accent'] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $theme['accent']) ? strtolower($theme['accent']) : '#1d4ed8';
        $style = in_array($theme['style'] ?? null, self::STYLES, true) ? $theme['style'] : 'bold';

        $pages = [];
        $slugs = [];
        foreach (array_slice(array_values(array_filter((array) ($content['pages'] ?? []), 'is_array')), 0, (int) config('sites.max_pages', 8)) as $i => $page) {
            $slug = Str::slug(Str::limit((string) ($page['slug'] ?? ''), 40, ''));
            if ($i === 0) {
                $slug = '';
            } elseif ($slug === '' || in_array($slug, $slugs, true)) {
                $slug = Str::slug((string) ($page['title'] ?? 'page')) ?: 'page';
                $slug = in_array($slug, $slugs, true) ? $slug.'-'.($i + 1) : $slug;
            }
            $slugs[] = $slug;

            $sections = [];
            $ids = [];
            foreach (array_slice(array_values(array_filter((array) ($page['sections'] ?? []), 'is_array')), 0, (int) config('sites.max_sections', 14)) as $j => $section) {
                $type = (string) ($section['type'] ?? '');
                if (! array_key_exists($type, self::TYPES)) {
                    continue;
                }
                $id = preg_match('/^[a-z0-9-]{1,20}$/', (string) ($section['id'] ?? '')) && ! in_array($section['id'], $ids, true) ? (string) $section['id'] : 's'.($j + 1).'-'.Str::lower(Str::random(4));
                $ids[] = $id;
                $sections[] = [
                    'id' => $id,
                    'type' => $type,
                    'heading' => self::text($section['heading'] ?? '', self::LIMITS['heading']),
                    'text' => self::text($section['text'] ?? '', self::LIMITS['text'], true),
                    'button' => self::text($section['button'] ?? '', self::LIMITS['button']),
                    'items' => array_values(array_filter(array_map(fn ($item) => is_array($item) ? [
                        'title' => self::text($item['title'] ?? '', self::LIMITS['item_title']),
                        'text' => self::text($item['text'] ?? '', self::LIMITS['item_text'], true),
                    ] : null, array_slice((array) ($section['items'] ?? []), 0, self::LIMITS['items'])), fn ($item) => $item && ($item['title'] !== '' || $item['text'] !== ''))),
                ];
            }

            $title = self::text($page['title'] ?? '', 40) ?: ($slug === '' ? 'Home' : Str::headline($slug));
            $pages[] = [
                'slug' => $slug,
                'title' => $title,
                'seo_title' => self::text($page['seo_title'] ?? '', 70),
                'seo_description' => self::text($page['seo_description'] ?? '', 160),
                'sections' => $sections,
            ];
        }

        if ($pages === []) {
            $pages[] = ['slug' => '', 'title' => 'Home', 'seo_title' => '', 'seo_description' => '', 'sections' => []];
        }

        return ['theme' => ['accent' => $accent, 'style' => $style], 'pages' => $pages];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>|null
     */
    public static function page(array $content, string $slug): ?array
    {
        foreach ($content['pages'] ?? [] as $page) {
            if (($page['slug'] ?? null) === $slug) {
                return $page;
            }
        }

        return null;
    }

    /** JSON Schema of the content, for the AI's structured answer. @return array<string, mixed> */
    public static function schema(): array
    {
        $string = ['type' => 'string'];
        $item = ['type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'text'], 'properties' => ['title' => $string, 'text' => $string]];
        $section = ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'type', 'heading', 'text', 'button', 'items'], 'properties' => [
            'id' => $string, 'type' => ['type' => 'string', 'enum' => array_keys(self::TYPES)], 'heading' => $string, 'text' => $string, 'button' => $string,
            'items' => ['type' => 'array', 'items' => $item],
        ]];
        $page = ['type' => 'object', 'additionalProperties' => false, 'required' => ['slug', 'title', 'seo_title', 'seo_description', 'sections'], 'properties' => [
            'slug' => $string, 'title' => $string, 'seo_title' => $string, 'seo_description' => $string, 'sections' => ['type' => 'array', 'items' => $section],
        ]];

        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['theme', 'pages'], 'properties' => [
            'theme' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['accent', 'style'], 'properties' => [
                'accent' => $string, 'style' => ['type' => 'string', 'enum' => self::STYLES],
            ]],
            'pages' => ['type' => 'array', 'items' => $page],
        ]];
    }

    private static function text(mixed $value, int $max, bool $multiline = false): string
    {
        $value = is_scalar($value) ? strip_tags((string) $value) : '';
        $value = $multiline ? preg_replace("/[ \t]+/", ' ', str_replace("\r", '', $value)) : preg_replace('/\s+/', ' ', $value);

        return Str::limit(trim((string) $value), $max, '');
    }
}
