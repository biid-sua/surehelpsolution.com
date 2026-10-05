<?php

namespace App\Services\Social\Publishers;

use App\Enums\SocialNetwork;
use App\Exceptions\SocialPostRejected;
use App\Models\SocialAccount;
use App\Services\Social\Connectors\MetaGraph;
use App\Services\Social\Contracts\SocialPublisher;
use App\Services\Social\Data\PublishRequest;
use App\Services\Social\Data\PublishResult;
use RuntimeException;

/**
 * Instagram professional accounts: one image, or a carousel of up to 10. Instagram builds a
 * "container" from our image link first, then publishes it.
 */
class InstagramPublisher implements SocialPublisher
{
    public function __construct(private readonly MetaGraph $graph) {}

    public function network(): SocialNetwork
    {
        return SocialNetwork::Instagram;
    }

    public function publish(SocialAccount $account, string $accessToken, PublishRequest $request): PublishResult
    {
        if ($request->images === []) {
            throw new SocialPostRejected('Instagram posts need at least one photo.');
        }

        $ig = $account->external_id;

        if (count($request->images) === 1) {
            $container = $this->graph->post("{$ig}/media", $accessToken, array_filter([
                'image_url' => $request->images[0]['url'], 'caption' => $request->text,
            ]));
        } else {
            $children = [];
            foreach (array_slice($request->images, 0, 10) as $image) {
                $item = $this->graph->post("{$ig}/media", $accessToken, array_filter([
                    'image_url' => $image['url'], 'is_carousel_item' => 'true',
                ]));
                $children[] = $item['id'];
            }
            $container = $this->graph->post("{$ig}/media", $accessToken, [
                'media_type' => 'CAROUSEL', 'children' => implode(',', $children), 'caption' => $request->text,
            ]);
        }

        $this->waitUntilReady((string) $container['id'], $accessToken);

        $published = $this->graph->post("{$ig}/media_publish", $accessToken, ['creation_id' => $container['id']]);
        $permalink = $this->graph->get((string) $published['id'], $accessToken, ['fields' => 'permalink'])['permalink'] ?? null;

        return new PublishResult((string) $published['id'], $permalink);
    }

    /**
     * Images are usually ready at once; give Instagram a few seconds before publishing.
     */
    private function waitUntilReady(string $containerId, string $accessToken): void
    {
        for ($try = 0; $try < 5; $try++) {
            $status = $this->graph->get($containerId, $accessToken, ['fields' => 'status_code'])['status_code'] ?? 'FINISHED';

            if ($status === 'FINISHED') {
                return;
            }
            if ($status === 'ERROR' || $status === 'EXPIRED') {
                throw new SocialPostRejected('Instagram could not process the image. Try a JPEG under 8 MB with an aspect ratio between 4:5 and 1.91:1.');
            }

            usleep((int) config('social.poll_ms', 1500) * 1000);
        }

        throw new RuntimeException('Instagram is still processing the image.');
    }
}
