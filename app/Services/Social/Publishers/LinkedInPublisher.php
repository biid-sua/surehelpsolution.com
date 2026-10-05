<?php

namespace App\Services\Social\Publishers;

use App\Enums\SocialNetwork;
use App\Models\SocialAccount;
use App\Services\Social\Connectors\LinkedInApi;
use App\Services\Social\Contracts\SocialPublisher;
use App\Services\Social\Data\PublishRequest;
use App\Services\Social\Data\PublishResult;
use Illuminate\Support\Facades\Storage;

/**
 * LinkedIn posts (Posts API) for a profile or a Company Page: text, plus one image or a link card.
 */
class LinkedInPublisher implements SocialPublisher
{
    public function __construct(private readonly LinkedInApi $api) {}

    public function network(): SocialNetwork
    {
        return SocialNetwork::LinkedIn;
    }

    public function publish(SocialAccount $account, string $accessToken, PublishRequest $request): PublishResult
    {
        $author = $account->external_id; // urn:li:person:… or urn:li:organization:…
        $body = [
            'author' => $author,
            'commentary' => LinkedInApi::commentary($request->text),
            'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        if ($request->images !== []) {
            $image = $request->images[0];
            $body['content'] = ['media' => array_filter(['id' => $this->upload($author, $accessToken, $image), 'altText' => $image['alt']])];
        } elseif ($request->link) {
            $body['content'] = ['article' => ['source' => $request->link, 'title' => (string) (parse_url($request->link, PHP_URL_HOST) ?: $request->link)]];
        }

        $response = $this->api->ok($this->api->client($accessToken)->post(LinkedInApi::REST.'/posts', $body));
        $urn = (string) ($response->header('x-restli-id') ?: $response->json('id'));

        return new PublishResult($urn, 'https://www.linkedin.com/feed/update/'.$urn);
    }

    /**
     * @param  array{url: string, disk: string, path: string, mime: string, alt: ?string}  $image
     */
    private function upload(string $owner, string $accessToken, array $image): string
    {
        $init = $this->api->ok($this->api->client($accessToken)->post(LinkedInApi::REST.'/images?action=initializeUpload', [
            'initializeUploadRequest' => ['owner' => $owner],
        ]))->json('value');

        $this->api->ok($this->api->client($accessToken)
            ->withBody((string) Storage::disk($image['disk'])->get($image['path']), $image['mime'])
            ->put($init['uploadUrl']));

        return (string) $init['image'];
    }
}
