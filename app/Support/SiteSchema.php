<?php

namespace App\Support;

/**
 * schema.org structured data for the public site (D58), so search engines understand who we are,
 * what each page offers and how pages relate. Built from config/site.php and config/company.php;
 * it only states what the pages themselves say (no ratings or reviews).
 */
final class SiteSchema
{
    /** @return array<string, mixed> */
    public static function organization(): array
    {
        $address = config('company.address');

        return array_filter([
            '@type' => 'Organization',
            '@id' => self::root().'#organization',
            'name' => config('site.name'),
            'alternateName' => config('site.alternate_name'),
            'url' => self::root(),
            'logo' => [
                '@type' => 'ImageObject',
                'url' => asset('assets/img/logo.png'),
                'width' => 505,
                'height' => 128,
            ],
            'email' => config('company.email'),
            'telephone' => config('site.phone'),
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $address['street'],
                'addressLocality' => $address['city'],
                'addressRegion' => $address['state'],
                'postalCode' => $address['zip'],
                'addressCountry' => 'US',
            ],
            'contactPoint' => [[
                '@type' => 'ContactPoint',
                'contactType' => 'sales',
                'telephone' => config('site.phone'),
                'email' => config('company.email'),
                'areaServed' => 'US',
                'availableLanguage' => ['English'],
            ]],
            'sameAs' => config('site.same_as'),
        ]);
    }

    /** @return array<string, mixed> */
    public static function website(): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => self::root().'#website',
            'url' => self::root(),
            'name' => config('site.name'),
            'publisher' => ['@id' => self::root().'#organization'],
            'inLanguage' => 'en-US',
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $trail  [name, url] pairs after Home
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $trail): array
    {
        $items = array_merge([['Home', self::root()]], $trail);

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $item, int $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $item[0],
                'item' => $item[1],
            ], $items, array_keys($items)),
        ];
    }

    /** @return array<string, mixed> */
    public static function service(string $name, string $description, string $url, ?string $audience = null): array
    {
        return array_filter([
            '@type' => 'Service',
            'name' => $name,
            'serviceType' => 'Telephone answering service',
            'description' => $description,
            'url' => $url,
            'provider' => ['@id' => self::root().'#organization'],
            'areaServed' => ['@type' => 'Country', 'name' => 'United States'],
            'audience' => $audience ? ['@type' => 'BusinessAudience', 'name' => $audience] : null,
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $faqs  [question, answer] pairs, exactly as shown on the page
     * @return array<string, mixed>
     */
    public static function faq(array $faqs): array
    {
        return [
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $qa) => [
                '@type' => 'Question',
                'name' => $qa[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]],
            ], $faqs),
        ];
    }

    /** The site's home address with its trailing slash, as search engines show it. */
    private static function root(): string
    {
        return rtrim(url('/'), '/').'/';
    }

    /**
     * One JSON-LD block holding several entities.
     *
     * @param  list<array<string, mixed>>  $graph
     */
    public static function script(array $graph): string
    {
        $json = json_encode(['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        return '<script type="application/ld+json">'.$json.'</script>';
    }
}
