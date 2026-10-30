<?php

namespace App\Services\Metrics;

use App\Enums\InboxChannel;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\SocialPostTarget;
use Carbon\CarbonImmutable;

/**
 * Where a business's customers and bookings came from (G-5, D68), counted from SureHelp's own records:
 * - new customers by their first way of getting in touch (phone, website form or booking, website chat,
 *   Facebook, Instagram, text), with those the business typed in or imported shown apart;
 * - bookings by who or what made them (SureHelp agents, online booking, the AI assistant, the business);
 * - the website (pages viewed with the snippet) and social media (posts published, messages received).
 * Reach and engagement from the networks themselves come later, after their API approvals.
 */
class SourceReport
{
    public const LEAD_SOURCES = [
        'phone' => 'Phone calls',
        'website' => 'Website form or online booking',
        'web_chat' => 'Website chat',
        'facebook' => 'Facebook Messenger',
        'instagram' => 'Instagram',
        'sms' => 'Text messages',
        'lead_sources' => 'Lead sources (Facebook, Google, Angi…)',
    ];

    public const BOOKING_SOURCES = [
        'agent' => 'By phone, booked by SureHelp',
        'website' => 'Online booking on your website',
        'ai' => 'AI assistant and receptionist',
        'business' => 'By your team',
    ];

    public function __construct(private readonly WebsiteViews $views) {}

    /**
     * @return array{
     *     leads: array<string, int>, leads_total: int, added_by_team: int,
     *     bookings: array<string, int>, bookings_total: int,
     *     website_views: int|null, website_leads: int, social_posts: int, social_messages: int,
     *     change: array<string, int|null>
     * }
     */
    public function compute(Organization $organization, CarbonImmutable $start, CarbonImmutable $end, bool $compare = true): array
    {
        $window = [$start->utc(), $end->utc()];

        $leads = array_fill_keys(array_keys(self::LEAD_SOURCES), 0);
        $addedByTeam = 0;
        $customers = Customer::query()->forOrganization($organization)->whereBetween('created_at', $window)->get(['id', 'source']);
        $firstChannel = $this->firstChannels($organization, $customers->where('source', 'message')->pluck('id')->all());
        foreach ($customers as $customer) {
            if (in_array($customer->source, ['quickbooks', 'jobber'], true)) {
                continue;   // already the business's customers, brought in from a connected app (D110, D111)
            }
            $key = match ($customer->source) {
                'call' => 'phone',
                'website' => 'website',
                'lead' => 'lead_sources',
                'chatbot' => 'web_chat',
                'message' => $firstChannel[$customer->id] ?? 'web_chat',
                default => null,
            };
            $key === null ? $addedByTeam++ : $leads[$key]++;
        }

        $bookings = array_fill_keys(array_keys(self::BOOKING_SOURCES), 0);
        $appointments = Appointment::query()->forOrganization($organization)->whereBetween('created_at', $window)
            ->where('source', '!=', 'calendar')   // copied in from a connected calendar: not a new booking
            ->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source');
        foreach ($appointments as $source => $n) {
            $bookings[match ($source) {
                'agent' => 'agent',
                'website' => 'website',
                'chatbot', 'ai', 'ai_phone' => 'ai',
                default => 'business',
            }] += (int) $n;
        }

        $local = fn (CarbonImmutable $t) => $t->setTimezone($organization->timezoneOrDefault());
        $result = [
            'leads' => $leads,
            'leads_total' => array_sum($leads),
            'added_by_team' => $addedByTeam,
            'bookings' => $bookings,
            'bookings_total' => array_sum($bookings),
            'website_views' => $this->views->between($organization, $local($start), $local($end)),
            'website_leads' => $leads['website'] + $leads['web_chat'],
            'social_posts' => SocialPostTarget::query()
                ->whereHas('post', fn ($q) => $q->forOrganization($organization))
                ->where('status', SocialPostTarget::PUBLISHED)->whereBetween('published_at', $window)->count(),
            'social_messages' => Conversation::query()->forOrganization($organization)
                ->whereIn('channel', [InboxChannel::Facebook->value, InboxChannel::Instagram->value])->whereBetween('created_at', $window)->count(),
            'change' => [],
        ];

        if ($compare) {
            $length = $start->diffInSeconds($end);
            $previous = $this->compute($organization, $start->subSeconds((int) $length + 1), $start->subSecond(), false);
            foreach (['leads_total', 'bookings_total', 'website_views', 'website_leads', 'social_posts', 'social_messages'] as $key) {
                $result['change'][$key] = ($previous[$key] ?? 0) > 0 && $result[$key] !== null
                    ? (int) round(($result[$key] - $previous[$key]) / $previous[$key] * 100) : null;
            }
        }

        return $result;
    }

    /**
     * The channel of each customer's first conversation.
     *
     * @param  list<int>  $customerIds
     * @return array<int, string>
     */
    private function firstChannels(Organization $organization, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $channels = [];
        Conversation::query()->forOrganization($organization)->whereIn('customer_id', $customerIds)
            ->orderBy('created_at')->orderBy('id')->get(['customer_id', 'channel'])
            ->each(function (Conversation $c) use (&$channels) {
                $channel = $c->channel->value;
                $channels[$c->customer_id] ??= array_key_exists($channel, self::LEAD_SOURCES) ? $channel : 'web_chat';
            });

        return $channels;
    }
}
