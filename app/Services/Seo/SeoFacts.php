<?php

namespace App\Services\Seo;

use App\Enums\KnowledgeVisibility;
use App\Models\BusinessLocation;
use App\Models\BusinessProfile;
use App\Models\BusinessService;
use App\Models\KnowledgeItem;
use App\Models\Organization;
use Illuminate\Support\Str;

/**
 * What the SEO writer may say about a business (D57): public facts only. Agent instructions and
 * internal or team-only knowledge never reach it, because everything it writes is meant for the web.
 */
class SeoFacts
{
    public function for(Organization $organization): string
    {
        $profile = BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->first();
        $location = BusinessLocation::withoutGlobalScopes()->where('organization_id', $organization->id)->orderByDesc('is_primary')->first();
        $settings = $organization->seo_settings ?? [];

        $lines = array_filter([
            'Business name: '.($profile?->display_name ?: $organization->name),
            $profile?->business_type ? 'Type of business: '.$profile->business_type : null,
            $profile?->industry ? 'Industry: '.$profile->industry : null,
            $profile?->description ? 'About: '.Str::limit(trim((string) $profile->description), 1500) : null,
            $location ? 'Address: '.implode(', ', array_filter([$location->address_line1, $location->city, $location->state, $location->postal_code])) : null,
            $profile?->service_area ? 'Service area: '.Str::limit(trim((string) $profile->service_area), 800) : null,
            ! empty($settings['areas']) ? 'Towns and areas to target: '.implode(', ', (array) $settings['areas']) : null,
            ! empty($settings['keywords']) ? 'Keywords the business wants to be found for: '.implode(', ', (array) $settings['keywords']) : null,
            $profile?->phone ? 'Phone: '.$profile->phone : null,
            $profile?->website ? 'Website: '.$profile->website : null,
            $profile?->emergency_available ? 'Offers emergency service.' : null,
        ]);

        $services = BusinessService::withoutGlobalScopes()->where('organization_id', $organization->id)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->limit(40)->get()
            ->map(fn (BusinessService $s) => '- '.$s->name.($s->description ? ': '.Str::limit(trim((string) $s->description), 300) : ''))
            ->implode("\n");

        $public = KnowledgeItem::withoutGlobalScopes()->where('organization_id', $organization->id)
            ->where('visibility', KnowledgeVisibility::Public->value)->orderBy('id')->limit(20)->get()
            ->map(fn (KnowledgeItem $k) => "### {$k->title}\n".Str::limit(trim((string) $k->content), 600))
            ->implode("\n\n");

        return "## The business\n- ".implode("\n- ", $lines)
            ."\n\n## Services\n".($services !== '' ? $services : '(none listed)')
            .($public !== '' ? "\n\n## Public information the business has written\n".$public : '');
    }
}
