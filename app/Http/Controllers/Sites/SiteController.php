<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteVersion;
use App\Services\Billing\FeatureAccess;
use App\Services\Sites\SiteRenderer;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Hosted websites (D99): the public pages at `{subdomain}.{sites.domain}` or `/site/{subdomain}`, and
 * the owner's preview of the draft (or any version) inside the portal.
 */
class SiteController extends Controller
{
    public function __construct(private readonly SiteRenderer $renderer) {}

    public function show(string $subdomain, string $page = ''): Response
    {
        $site = Site::withoutGlobalScopes()->where('subdomain', strtolower($subdomain))->with(['organization', 'published'])->first();
        abort_unless($site && $site->published && $site->organization?->isServing()
            && app(FeatureAccess::class)->allows($site->organization, 'website_builder'), 404);

        return response($this->renderer->render($site, $site->published, $page))
            ->header('Cache-Control', 'public, max-age=300');
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
