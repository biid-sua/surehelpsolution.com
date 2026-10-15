<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * sitemap.xml and robots.txt for the public website (D58). Built from the routes, so new menu
 * pages are listed automatically. The private areas are kept out of crawlers' way; they also
 * answer with a noindex header (SearchIndexing).
 */
class SitemapController extends Controller
{
    public const PRIVATE_PATHS = ['/app/', '/admin/', '/agent/', '/account/', '/api/', '/appointment/', '/invitations/', '/media/', '/livewire/'];

    public function sitemap(): Response
    {
        $pagesUpdated = filemtime(config_path('pages.php')) ?: time();
        $siteUpdated = max($pagesUpdated, filemtime(config_path('site.php')) ?: 0);

        $urls = [[route('home'), filemtime(resource_path('views/home.blade.php')) ?: $siteUpdated, '1.0']];
        foreach (config('pages') as $slug => $page) {
            $priority = match ($page['group']) {
                'products', 'solutions' => '0.9',
                'resources' => '0.7',
                default => '0.5',
            };
            $urls[] = [route('pages.show', $slug), $siteUpdated, $priority];
        }
        $urls[] = [route('legal.faq'), $siteUpdated, '0.8'];
        foreach (['terms-of-use', 'privacy-policy', 'data-processing-addendum', 'business-associate-agreement', 'data-security', 'cookie-notice'] as $legal) {
            $urls[] = [route('legal.'.$legal), filemtime(resource_path("views/legal/{$legal}.blade.php")) ?: $siteUpdated, '0.3'];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as [$loc, $updated, $priority]) {
            $xml .= '  <url><loc>'.e($loc).'</loc><lastmod>'.gmdate('Y-m-d', $updated).'</lastmod><priority>'.$priority.'</priority></url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function robots(): Response
    {
        $lines = ['User-agent: *'];
        foreach (self::PRIVATE_PATHS as $path) {
            $lines[] = 'Disallow: '.$path;
        }
        // The chat widget script is loaded by businesses' own websites; crawlers rendering them need it.
        $lines[] = 'Allow: /api/chat/widget.js';
        $lines[] = '';
        $lines[] = 'Sitemap: '.route('seo.sitemap');

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
