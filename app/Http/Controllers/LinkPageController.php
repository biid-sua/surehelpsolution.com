<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Business\Branding;
use App\Services\Growth\LinkPage;
use App\Support\CustomerLocale;
use Illuminate\Contracts\View\View;

/**
 * A business's public link-in-bio page (D69).
 */
class LinkPageController extends Controller
{
    public function __invoke(string $slug, LinkPage $page): View
    {
        $organization = Organization::query()->where('slug', $slug)->first();
        abort_unless($organization && $page->isLive($organization), 404);

        CustomerLocale::apply($organization);   // D92

        return view('link-page', ['page' => $page->content($organization), 'brand' => app(Branding::class)->for($organization)]);
    }
}
