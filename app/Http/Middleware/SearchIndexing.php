<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the public website goes into search results (D58). Everything else (sign-in, portals,
 * customer self-service links, invitations, media links, the API) is marked noindex, so a
 * shared or leaked link never shows up in Google.
 */
class SearchIndexing
{
    /** Routes that make up the public website. */
    public const PUBLIC_ROUTES = ['home', 'pages.show', 'legal.*', 'seo.sitemap', 'seo.robots'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->routeIs(...self::PUBLIC_ROUTES) && ! $response->headers->has('X-Robots-Tag')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
