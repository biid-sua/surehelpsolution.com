<?php

/*
 * Websites we host for businesses (D99, docs/hosted-sites.md).
 */
return [
    // Sites live at {subdomain}.{domain} once a wildcard DNS record and certificate point here. Until
    // then (empty), they're served at /site/{subdomain} on this app's own address.
    'domain' => env('SITES_DOMAIN'),

    // Businesses' own domains (D101, docs/hosted-sites.md). The driver issues the certificates:
    //   manual: this server does (e.g. Caddy on-demand TLS asking /sites/domain-allowed, or Forge);
    //   cloudflare: Cloudflare for SaaS custom hostnames on the zone below;
    //   fake: tests.
    // Owners point a CNAME at `cname_target` (e.g. sites.surehelp.site); a bare domain (no www)
    // needs an A record to `ip`, so it's only offered when that's set.
    'custom_domains' => [
        'driver' => env('SITES_DOMAIN_DRIVER', 'manual'),
        'cname_target' => env('SITES_CNAME_TARGET'),
        'ip' => env('SITES_IP'),
        'cloudflare' => [
            'token' => env('CLOUDFLARE_API_TOKEN'),
            'zone' => env('CLOUDFLARE_ZONE_ID'),
        ],
    ],

    // Subdomains businesses can't take.
    'reserved' => ['www', 'app', 'api', 'admin', 'mail', 'email', 'help', 'support', 'status', 'blog', 'docs', 'billing', 'login', 'surehelp', 'demo', 'test', 'staging'],

    // AI edits: tokens per calendar month (staff can't change it per business yet; a plan can, with
    // the `site_ai_tokens` limit), and the least left to start one.
    'monthly_tokens' => (int) env('SITES_MONTHLY_TOKENS', 150000),
    'min_tokens_to_start' => 8000,
    'effort' => 'medium',
    'max_output_tokens' => 16000,

    'max_pages' => 8,
    'max_sections' => 14,

    // The look of each template: colours come from the business's brand colour (or `accent`),
    // `style` picks the type and shapes, and `sections` is the home page's starting order.
    'templates' => [
        'bold' => [
            'label' => 'Bold',
            'description' => 'Big headline and strong buttons. Suits plumbers, electricians, HVAC and other trades.',
            'accent' => '#1d4ed8',
            'style' => 'bold',
            'sections' => ['hero', 'services', 'reviews', 'area', 'hours', 'faq', 'cta'],
        ],
        'clean' => [
            'label' => 'Clean',
            'description' => 'Light and calm, lots of white space. Suits cleaning, landscaping and home services.',
            'accent' => '#0f766e',
            'style' => 'clean',
            'sections' => ['hero', 'services', 'text', 'reviews', 'hours', 'cta'],
        ],
        'care' => [
            'label' => 'Care',
            'description' => 'Warm and reassuring. Suits clinics, dentists, therapists and salons.',
            'accent' => '#9333ea',
            'style' => 'warm',
            'sections' => ['hero', 'text', 'services', 'reviews', 'faq', 'hours', 'contact'],
        ],
    ],
];
