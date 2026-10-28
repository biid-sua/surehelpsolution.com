<?php

namespace App\Services\Sites;

use App\Models\BusinessLocation;
use App\Models\BusinessProfile;
use App\Models\ChatWidget;
use App\Models\Site;
use App\Models\SiteVersion;
use App\Services\Billing\FeatureAccess;
use App\Services\Business\BusinessHours;
use App\Support\CustomerLocale;
use App\Support\Phone;

/**
 * Turns a site version into its HTML page (D99). Live details (phone, address, opening hours, the
 * booking and chat button) come from the business at render time, so they're never out of date.
 */
class SiteRenderer
{
    public function render(Site $site, SiteVersion $version, string $slug, bool $preview = false): string
    {
        $content = SiteContent::normalize($version->content);
        $page = SiteContent::page($content, $slug);
        abort_unless($page !== null, 404);

        $organization = $site->organization;
        CustomerLocale::apply($organization);
        $profile = BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->first();
        $location = BusinessLocation::withoutGlobalScopes()->where('organization_id', $organization->id)->orderByDesc('is_primary')->first();
        $widget = ChatWidget::withoutGlobalScopes()->where('organization_id', $organization->id)->where('is_enabled', true)->first();
        $tools = app(FeatureAccess::class)->allows($organization, 'website_tools');
        $phone = Phone::normalize($profile?->phone ?: $location?->phone);
        $address = $location ? trim(implode(', ', array_filter([$location->address_line1, $location->city, trim($location->state.' '.$location->postal_code)]))) : null;

        $books = $widget && $tools && $widget->offers('booking');
        $name = $profile?->display_name ?: $organization->name;

        return view('sites.page', [
            'site' => $site,
            'preview' => $preview,
            'version' => $version,
            'theme' => $content['theme'],
            'pages' => $content['pages'],
            'page' => $page,
            'name' => $name,
            'logo' => $profile?->logoUrl(),
            'phone' => $phone,
            'email' => $profile?->email,
            'address' => $address,
            'hours' => app(BusinessHours::class)->weekly($organization),
            'widget' => $widget,
            // Booking opens the SureHelp snippet when the business takes bookings; otherwise the button calls.
            'action' => $books ? ['href' => '#surehelp-book', 'kind' => 'book'] : ($phone ? ['href' => 'tel:'.$phone, 'kind' => 'call'] : ['href' => '#contact', 'kind' => 'contact']),
            'pageUrl' => fn (string $slug) => $preview
                ? route('app.site.preview', array_filter(['page' => $slug, 'version' => request()->query('version')]))
                : $site->url($slug),
            'schema' => array_filter([
                '@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $name,
                'description' => $profile?->description, 'telephone' => $phone, 'email' => $profile?->email,
                'url' => $site->url(), 'image' => $profile?->logoUrl(),
                'address' => $location ? array_filter(['@type' => 'PostalAddress', 'streetAddress' => $location->address_line1, 'addressLocality' => $location->city,
                    'addressRegion' => $location->state, 'postalCode' => $location->postal_code, 'addressCountry' => $location->country ?: 'US']) : null,
                'areaServed' => $profile?->service_area,
            ]),
        ])->render();
    }
}
