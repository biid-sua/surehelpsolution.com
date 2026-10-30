<?php

namespace App\Services\Social\Publishers;

use App\Enums\SocialNetwork;
use App\Exceptions\SocialPostRejected;
use App\Models\SocialAccount;
use App\Services\Social\Connectors\MetaGraph;
use App\Services\Social\Contracts\SocialPublisher;
use App\Services\Social\Data\PublishRequest;
use App\Services\Social\Data\PublishResult;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Instagram professional accounts: one image, a carousel of up to 10, or a video as a Reel (D104).
 * Instagram builds a "container" from our file link first, then publishes it. A Reel can take minutes
 * to process: the container is remembered, so a retry carries on waiting instead of uploading again.
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
        $ig = $account->external_id;

        if ($video = $request->video()) {
            $key = 'ig-reel:'.$account->id.':'.sha1($video['path']);
            $containerId = Cache::get($key) ?? (string) $this->graph->post("{$ig}/media", $accessToken, [
                'media_type' => 'REELS', 'video_url' => $video['url'], 'caption' => $request->text, 'share_to_feed' => 'true',
            ])['id'];
            Cache::put($key, $containerId, now()->addDay());
            $this->waitUntilReady($containerId, $accessToken, attempts: (int) config('social.video_poll_attempts', 20), video: true);
            Cache::forget($key);

            return $this->publishContainer($ig, $containerId, $accessToken);
        }

        if ($request->images === []) {
            throw new SocialPostRejected('Instagram posts need at least one photo or a video.');
        }

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

        return $this->publishContainer($ig, (string) $container['id'], $accessToken);
    }

    private function publishContainer(string $ig, string $containerId, string $accessToken): PublishResult
    {
        $published = $this->graph->post("{$ig}/media_publish", $accessToken, ['creation_id' => $containerId]);
        $permalink = $this->graph->get((string) $published['id'], $accessToken, ['fields' => 'permalink'])['permalink'] ?? null;

        return new PublishResult((string) $published['id'], $permalink);
    }

    /**
     * Images are usually ready at once; give Instagram a few seconds before publishing. Reels take longer:
     * if one isn't ready yet, the publish is retried later with the same container.
     */
    private function waitUntilReady(string $containerId, string $accessToken, int $attempts = 5, bool $video = false): void
    {
        for ($try = 0; $try < $attempts; $try++) {
            $status = $this->graph->get($containerId, $accessToken, ['fields' => 'status_code'])['status_code'] ?? 'FINISHED';

            if ($status === 'FINISHED') {
                return;
            }
            if ($status === 'ERROR' || $status === 'EXPIRED') {
                throw new SocialPostRejected($video
                    ? 'Instagram could not process the video. Reels should be MP4 (H.264), 3 seconds to 15 minutes, ideally 9:16.'
                    : 'Instagram could not process the image. Try a JPEG under 8 MB with an aspect ratio between 4:5 and 1.91:1.');
            }

            usleep((int) config('social.poll_ms', 1500) * 1000);
        }

        throw new RuntimeException($video ? 'Instagram is still processing the video.' : 'Instagram is still processing the image.');
    }
}
