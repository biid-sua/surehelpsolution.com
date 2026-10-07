<?php

namespace App\Services\Websites;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Read-only view of one HTML page for the website check. Never executes or renders anything.
 */
final class HtmlPage
{
    private DOMXPath $xpath;

    public function __construct(public readonly string $url, string $html)
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.($html === '' ? '<html></html>' : $html), LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->xpath = new DOMXPath($dom);
    }

    public function title(): ?string
    {
        $title = $this->text('//title');

        return $title === '' ? null : $title;
    }

    public function meta(string $name): ?string
    {
        foreach ($this->xpath->query('//meta[@name or @property]') ?: [] as $node) {
            /** @var DOMElement $node */
            $key = strtolower($node->getAttribute('name') ?: $node->getAttribute('property'));
            if ($key === strtolower($name)) {
                return trim($node->getAttribute('content'));
            }
        }

        return null;
    }

    public function count(string $query): int
    {
        $nodes = $this->xpath->query($query);

        return $nodes === false ? 0 : $nodes->length;
    }

    /**
     * Visible text, whitespace collapsed (scripts and styles left out).
     */
    public function visibleText(): string
    {
        $parts = [];
        foreach ($this->xpath->query('//body//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript)]') ?: [] as $node) {
            $parts[] = $node->textContent;
        }
        foreach ($this->xpath->query('//a[starts-with(@href, "tel:")]/@href') ?: [] as $href) {
            $parts[] = $href->textContent;   // phone numbers often only appear in click-to-call links
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /**
     * Absolute link targets on this page.
     *
     * @return list<string>
     */
    public function links(SafeFetcher $fetcher): array
    {
        $links = [];
        foreach ($this->xpath->query('//a[@href]/@href') ?: [] as $href) {
            $value = trim($href->textContent);
            if ($value === '' || preg_match('#^(mailto:|tel:|javascript:|data:|\#)#i', $value)) {
                continue;
            }
            $links[] = (string) preg_replace('/#.*$/', '', $fetcher->absolute($value, $this->url));
        }

        return array_values(array_unique(array_filter($links)));
    }

    /**
     * JSON-LD @type values on the page.
     *
     * @return list<string>
     */
    public function structuredDataTypes(): array
    {
        $types = [];
        foreach ($this->xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $data = json_decode($script->textContent, true);
            if (! is_array($data)) {
                continue;
            }
            array_walk_recursive($data, function ($value, $key) use (&$types) {
                if ($key === '@type' && is_string($value)) {
                    $types[] = $value;
                }
            });
            foreach ((array) ($data['@type'] ?? []) as $type) {
                $types[] = (string) $type;
            }
        }

        return array_values(array_unique($types));
    }

    private function text(string $query): string
    {
        $node = ($this->xpath->query($query) ?: null)?->item(0);

        return $node ? trim((string) preg_replace('/\s+/u', ' ', $node->textContent)) : '';
    }
}
