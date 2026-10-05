<?php

namespace App\Services\Social\Publishers;

use App\Enums\SocialNetwork;
use App\Models\SocialAccount;
use App\Services\Social\Connectors\MetaGraph;
use App\Services\Social\Contracts\SocialPublisher;
use App\Services\Social\Data\PublishRequest;
use App\Services\Social\Data\PublishResult;

/**
 * Facebook Page posts: text with a link preview, one photo, or several photos in one post.
 */
class FacebookPublisher implements SocialPublisher
{
    public function __construct(private readonly MetaGraph $graph) {}

    public function network(): SocialNetwork
    {
        return SocialNetwork::Facebook;
    }

    public function publish(SocialAccount $account, string $accessToken, PublishRequest $request): PublishResult
    {
        $page = $account->external_id;

        if ($request->images === []) {
            $json = $this->graph->post("{$page}/feed", $accessToken, array_filter(['message' => $request->text, 'link' => $request->link]));

            return $this->result((string) $json['id']);
        }

        // Photo posts can't carry a link preview: the link goes in the text instead.
        $message = $request->link && ! str_contains($request->text, $request->link) ? $request->text."\n\n".$request->link : $request->text;

        if (count($request->images) === 1) {
            $image = $request->images[0];
            $json = $this->graph->post("{$page}/photos", $accessToken, array_filter([
                'url' => $image['url'], 'caption' => $message,
            ]));

            return $this->result((string) ($json['post_id'] ?? $json['id']));
        }

        // Several photos: upload each unpublished, then attach them all to one post.
        $attached = [];
        foreach ($request->images as $i => $image) {
            $photo = $this->graph->post("{$page}/photos", $accessToken, array_filter([
                'url' => $image['url'], 'published' => 'false',
            ]));
            $attached["attached_media[{$i}]"] = json_encode(['media_fbid' => $photo['id']]);
        }
        $json = $this->graph->post("{$page}/feed", $accessToken, ['message' => $message] + $attached);

        return $this->result((string) $json['id']);
    }

    private function result(string $id): PublishResult
    {
        return new PublishResult($id, 'https://www.facebook.com/'.$id);
    }
}
