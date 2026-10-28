<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteVersion;
use App\Services\Billing\FeatureAccess;
use App\Services\Sites\SiteRenderer;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Hosted websites (D99): the public pages at `{subdomain}.{sites.domain}` or `/site/{subdomain}`, and
 * the owner's preview of the draft (or any version) inside the portal.
 */
class SiteController extends Controller
{
    public function __construct(private readonly SiteRenderer $renderer) {}

    public function show(string $subdomain, string $page = ''): Response|RedirectResponse
    {
        $site = Site::withoutGlobalScopes()->where('subdomain', strtolower($subdomain))->with(['organization', 'published'])->first();
        abort_unless($site && $site->published && $site->organization?->isServing()
            && app(FeatureAccess::class)->allows($site->organization, 'website_builder'), 404);
        // Once the business's own domain works, the free address sends visitors (and Google) there (D101).
        if (($own = $site->liveDomain()) && request()->getHost() !== $own) {
            return redirect()->away($site->url($page), 301);
        }

        return $this->publicPage($site, $page);
    }

    /** A visitor on the business's own domain: the middleware found the site by its host. */
    public function onOwnDomain(Site $site, string $page): Response
    {
        abort_unless($site->published && $site->organization?->isServing()
            && app(FeatureAccess::class)->allows($site->organization, 'website_builder'), 404);

        return $this->publicPage($site, $page);
    }

    /** A business's public page: cached briefly, and open to search engines (they're its website). */
    private function publicPage(Site $site, string $page): Response
    {
        return response($this->renderer->render($site, $site->published, $page))
            ->header('Cache-Control', 'public, max-age=300')
            ->header('X-Robots-Tag', 'all');
    }

    /**
     * For Caddy's on-demand TLS (manual driver): may a certificate be issued for this domain? Only
     * for domains a business has connected, so nobody can make us request certificates for others.
     */
    public function domainAllowed(Request $request): Response
    {
        $host = strtolower((string) $request->query('domain'));
        $known = $host !== '' && Site::withoutGlobalScopes()->where('custom_domain', $host)->whereIn('domain_status', ['pending', 'active'])->exists();

        return response($known ? 'ok' : 'unknown', $known ? 200 : 404)->header('Cache-Control', 'no-store');
    }

    public function preview(Request $request, string $page = ''): Response
    {
        $organization = app(CurrentOrganization::class)->get();
        abort_unless($organization && $request->user()?->can('integrations.view', $organization), 403);
        $site = Site::query()->forOrganization($organization)->firstOrFail();
        $version = $request->filled('version')
            ? SiteVersion::query()->forOrganization($organization)->where('site_id', $site->id)->where('number', (int) $request->query('version'))->firstOrFail()
            : $site->draft();

        return response($this->renderer->render($site, $version, $page, preview: true))
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex');
    }
}
