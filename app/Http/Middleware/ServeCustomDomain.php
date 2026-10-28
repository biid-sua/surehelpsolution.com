<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Sites\SiteController;
use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Visitors who arrive on a business's own domain (D101) see its hosted website, whatever the path.
 * Requests for our own addresses (the app, the sites domain and its subdomains) and for hosts no
 * business has connected pass straight on.
 */
class ServeCustomDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        if ($this->isOurs($host) || ! $request->isMethodSafe()) {
            return $next($request);
        }

        $site = Site::withoutGlobalScopes()->where('custom_domain', $host)->with(['organization', 'published'])->first();
        if (! $site) {
            return $next($request);
        }
        // Connected but not working yet (or stopped pointing here): a plain note, never our own pages.
        if ($site->domain_status !== 'active') {
            return response()->view('sites.setting-up', ['host' => $host], 404)->header('X-Robots-Tag', 'noindex');
        }
        $path = trim($request->path(), '/');
        abort_unless($path === '' || preg_match('/^[a-z0-9-]{1,40}$/', $path), 404);

        return app(SiteController::class)->onOwnDomain($site, $path);
    }

    private function isOurs(string $host): bool
    {
        $own = array_filter([parse_url((string) config('app.url'), PHP_URL_HOST), config('sites.domain'), 'localhost', '127.0.0.1']);

        return collect($own)->contains(fn (string $d) => $host === $d || str_ends_with($host, '.'.$d));
    }
}
