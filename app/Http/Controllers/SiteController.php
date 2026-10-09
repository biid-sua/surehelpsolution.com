<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The public website's pages (D55). Words, prices and claims come from config/marketing.php;
 * industry details from config/industries.php, the same source as the setup wizard.
 */
class SiteController extends Controller
{
    public function service(string $service): View
    {
        $services = config('marketing.services');

        return view('site.service', [
            'slug' => $service,
            'service' => $services[$service],
            'others' => collect($services)->except($service),
        ]);
    }

    public function how(): View
    {
        return view('site.how');
    }

    public function industries(): View
    {
        return view('site.industries', ['industries' => $this->industryList()]);
    }

    public function industry(string $industry): View
    {
        return view('site.industry', [
            'slug' => $industry,
            'marketing' => config("marketing.industries.{$industry}"),
            'details' => config("industries.{$industry}"),
            'others' => collect($this->industryList())->except($industry),
        ]);
    }

    public function pricing(): View
    {
        return view('site.pricing', ['plans' => config('marketing.plans')]);
    }

    public function about(): View
    {
        return view('site.about');
    }

    public function contact(Request $request): View
    {
        return view('site.contact', ['topic' => $request->query('topic')]);
    }

    /** For search engines: every public page. */
    public function sitemap(): Response
    {
        $urls = collect([route('home'), route('site.how'), route('site.pricing'), route('site.about'), route('site.contact'), route('site.industries.index'), route('legal.faq')])
            ->merge(collect(array_keys(config('marketing.services')))->map(fn ($s) => route('site.services.show', $s)))
            ->merge(collect(array_keys(config('marketing.industries')))->map(fn ($i) => route('site.industries.show', $i)))
            ->merge(collect(['privacy-policy', 'terms-of-use', 'data-security', 'cookie-notice', 'data-processing-addendum', 'business-associate-agreement'])->map(fn ($p) => route('legal.'.$p)));

        return response()->view('site.sitemap', ['urls' => $urls], 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** @return array<string, array{label: string, headline: string, intro: string}> */
    private function industryList(): array
    {
        return collect(config('marketing.industries'))
            ->map(fn (array $m, string $key) => ['label' => config("industries.{$key}.label", ucfirst($key))] + $m)
            ->all();
    }
}
