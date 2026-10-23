<?php

namespace App\Services\Growth;

use App\Models\BusinessLocation;
use App\Models\BusinessProfile;
use App\Models\ChatWidget;
use App\Models\Organization;
use App\Services\Billing\FeatureAccess;
use App\Support\Phone;

/**
 * The business's link-in-bio page (G-6, D69): one public address for Instagram, Facebook and TikTok bios
 * with the ways to reach the business: book online, call, send a message, its website and its own links.
 * Booking and messages open the SureHelp website snippet on the page.
 */
class LinkPage
{
    public const MAX_LINKS = 6;

    public function __construct(private readonly FeatureAccess $features) {}

    public function url(Organization $organization): string
    {
        return route('link-page', $organization->slug);
    }

    public function isLive(Organization $organization): bool
    {
        return $organization->isServing()
            && (bool) BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->value('link_page_enabled');
    }

    /**
     * @return array{name: string, about: ?string, logo: ?string, area: ?string, buttons: list<array{label: string, href: string, kind: string}>, widget: ?ChatWidget}
     */
    public function content(Organization $organization): array
    {
        $profile = BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->first();
        $widget = ChatWidget::withoutGlobalScopes()->where('organization_id', $organization->id)->first();
        $tools = $this->features->allows($organization, 'website_tools');
        $phone = Phone::normalize($profile?->phone);
        $location = BusinessLocation::withoutGlobalScopes()->where('organization_id', $organization->id)->orderByDesc('is_primary')->first();

        $buttons = [];
        if ($widget && $tools && $widget->offers('booking')) {
            $buttons[] = ['label' => __('Book online'), 'href' => '#surehelp-book', 'kind' => 'primary'];
        }
        if ($phone) {
            $buttons[] = ['label' => __('Call :phone', ['phone' => Phone::display($phone)]), 'href' => 'tel:'.$phone, 'kind' => 'phone'];
        }
        if ($widget && $tools && $widget->offers('lead')) {
            $buttons[] = ['label' => __('Send us a message'), 'href' => '#surehelp-lead', 'kind' => 'link'];
        } elseif ($widget && $widget->offers('chat')) {
            $buttons[] = ['label' => __('Chat with us'), 'href' => '#surehelp-chat', 'kind' => 'link'];
        }
        if ($profile?->website) {
            $buttons[] = ['label' => __('Visit our website'), 'href' => $profile->website, 'kind' => 'link'];
        }
        foreach (array_slice((array) $profile?->link_page_links, 0, self::MAX_LINKS) as $link) {
            if (filled($link['label'] ?? null) && filled($link['url'] ?? null)) {
                $buttons[] = ['label' => (string) $link['label'], 'href' => (string) $link['url'], 'kind' => 'link'];
            }
        }

        return [
            'name' => $profile?->display_name ?: $organization->name,
            'about' => $profile?->description,
            'logo' => $profile?->logoUrl(),
            'area' => $location?->city ? trim($location->city.($location->state ? ', '.$location->state : '')) : $profile?->service_area,
            'buttons' => $buttons,
            'widget' => $widget,
        ];
    }
}
