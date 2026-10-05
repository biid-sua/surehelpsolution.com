<?php

namespace App\Services\Social\Publishers;

use App\Enums\SocialNetwork;
use App\Models\SocialAccount;
use App\Services\Social\Connectors\GoogleBusinessConnector;
use App\Services\Social\Contracts\SocialPublisher;
use App\Services\Social\Data\PublishRequest;
use App\Services\Social\Data\PublishResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Google Business Profile posts: an update, offer or event, with an optional button.
 */
class GoogleBusinessPublisher implements SocialPublisher
{
    /** Button labels Google offers (actionType). CALL uses the profile's phone number, so it has no link. */
    public const BUTTONS = ['BOOK' => 'Book', 'ORDER' => 'Order online', 'SHOP' => 'Buy', 'LEARN_MORE' => 'Learn more', 'SIGN_UP' => 'Sign up', 'CALL' => 'Call now'];

    public const TOPICS = ['STANDARD' => 'Update', 'OFFER' => 'Offer', 'EVENT' => 'Event'];

    public function network(): SocialNetwork
    {
        return SocialNetwork::GoogleBusiness;
    }

    public function publish(SocialAccount $account, string $accessToken, PublishRequest $request): PublishResult
    {
        $options = $request->options;
        $topic = array_key_exists($options['topic'] ?? '', self::TOPICS) ? $options['topic'] : 'STANDARD';
        $body = ['languageCode' => 'en-US', 'summary' => $request->text, 'topicType' => $topic];

        $button = $options['button'] ?? ($request->link ? 'LEARN_MORE' : null);
        if ($button && array_key_exists($button, self::BUTTONS)) {
            $body['callToAction'] = $button === 'CALL'
                ? ['actionType' => 'CALL']
                : ['actionType' => $button, 'url' => (string) ($options['button_url'] ?? $request->link)];
        }

        if ($request->images !== []) {
            $body['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $request->images[0]['url']]];
        }

        if ($topic !== 'STANDARD') {
            $body['event'] = [
                'title' => (string) ($options['title'] ?? 'Special offer'),
                'schedule' => ['startDate' => $this->date($options['starts_on'] ?? null), 'endDate' => $this->date($options['ends_on'] ?? null, 7)],
            ];
        }
        if ($topic === 'OFFER') {
            $body['offer'] = array_filter([
                'couponCode' => $options['coupon'] ?? null,
                'redeemOnlineUrl' => $request->link,
                'termsConditions' => $options['terms'] ?? null,
            ]);
        }

        $json = GoogleBusinessConnector::ok(Http::withToken($accessToken)->acceptJson()->timeout(30)
            ->post('https://mybusiness.googleapis.com/v4/'.$account->external_id.'/localPosts', $body))->json();

        return new PublishResult((string) $json['name'], $json['searchUrl'] ?? null);
    }

    /**
     * @return array{year: int, month: int, day: int}
     */
    private function date(?string $value, int $daysFromNow = 0): array
    {
        $date = $value ? CarbonImmutable::parse($value) : CarbonImmutable::now()->addDays($daysFromNow);

        return ['year' => $date->year, 'month' => $date->month, 'day' => $date->day];
    }
}
