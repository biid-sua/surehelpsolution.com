<?php

namespace App\Services\Social\Publishers;

use App\Enums\SocialNetwork;
use App\Exceptions\SocialPostRejected;
use App\Models\SocialAccount;
use App\Services\Social\Connectors\XConnector;
use App\Services\Social\Contracts\SocialPublisher;
use App\Services\Social\Data\PublishRequest;
use App\Services\Social\Data\PublishResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * X posts (D104): text, up to four photos or one video. Media goes up first through the v2 media
 * endpoints (photos in one request; videos initialize → append parts → finalize, then X processes
 * them). A video still processing is remembered, so a retry carries on instead of uploading again.
 */
class XPublisher implements SocialPublisher
{
    private const CHUNK = 4 * 1024 * 1024;

    public function network(): SocialNetwork
    {
        return SocialNetwork::X;
    }

    public function publish(SocialAccount $account, string $accessToken, PublishRequest $request): PublishResult
    {
        $mediaIds = [];
        if ($video = $request->video()) {
            $mediaIds[] = $this->video($account, $accessToken, $video);
        } else {
            foreach (array_slice($request->images, 0, 4) as $image) {
                $mediaIds[] = $this->image($accessToken, $image);
            }
        }

        $text = $request->link && ! str_contains($request->text, $request->link) ? trim($request->text.' '.$request->link) : $request->text;
        $json = XConnector::ok(Http::withToken($accessToken)->acceptJson()->post(XConnector::API.'/tweets', array_filter([
            'text' => $text,
            'media' => $mediaIds === [] ? null : ['media_ids' => $mediaIds],
        ])))->json('data');

        $handle = ltrim((string) $account->handle, '@') ?: 'i';

        return new PublishResult((string) $json['id'], 'https://x.com/'.$handle.'/status/'.$json['id']);
    }

    /** @param  array{url: string, disk: string, path: string, mime: string, alt: ?string}  $image */
    private function image(string $accessToken, array $image): string
    {
        $json = XConnector::ok(Http::withToken($accessToken)->acceptJson()
            ->attach('media', (string) Storage::disk($image['disk'])->get($image['path']), basename($image['path']), ['Content-Type' => $image['mime']])
            ->post(XConnector::API.'/media/upload', ['media_category' => 'tweet_image', 'media_type' => $image['mime']]))->json('data');

        return (string) $json['id'];
    }

    /** @param  array{url: string, disk: string, path: string, mime: string, alt: ?string, size: int}  $video */
    private function video(SocialAccount $account, string $accessToken, array $video): string
    {
        $key = 'x-video:'.$account->id.':'.sha1($video['path']);
        $id = Cache::get($key);

        if (! $id) {
            $disk = Storage::disk($video['disk']);
            $size = $video['size'] ?: (int) $disk->size($video['path']);
            $id = (string) XConnector::ok(Http::withToken($accessToken)->acceptJson()->post(XConnector::API.'/media/upload/initialize', [
                'media_type' => $video['mime'], 'total_bytes' => $size, 'media_category' => 'tweet_video',
            ]))->json('data.id');

            $stream = $disk->readStream($video['path']);
            try {
                for ($segment = 0; ! feof($stream); $segment++) {
                    $chunk = (string) fread($stream, self::CHUNK);
                    if ($chunk === '') {
                        break;
                    }
                    XConnector::ok(Http::withToken($accessToken)->acceptJson()
                        ->attach('media', $chunk, 'chunk'.$segment, ['Content-Type' => 'application/octet-stream'])
                        ->post(XConnector::API.'/media/upload/'.$id.'/append', ['segment_index' => $segment]));
                }
            } finally {
                is_resource($stream) && fclose($stream);
            }

            $info = XConnector::ok(Http::withToken($accessToken)->acceptJson()->post(XConnector::API.'/media/upload/'.$id.'/finalize'))->json('data.processing_info');
            Cache::put($key, $id, now()->addDay());
            if (! $info) {
                Cache::forget($key);

                return $id;
            }
        }

        for ($try = 0; $try < (int) config('social.video_poll_attempts', 20); $try++) {
            $info = XConnector::ok(Http::withToken($accessToken)->acceptJson()
                ->get(XConnector::API.'/media/upload', ['command' => 'STATUS', 'media_id' => $id]))->json('data.processing_info');
            $state = $info['state'] ?? 'succeeded';
            if ($state === 'succeeded') {
                Cache::forget($key);

                return $id;
            }
            if ($state === 'failed') {
                Cache::forget($key);

                throw new SocialPostRejected('X could not process the video: '.($info['error']['message'] ?? 'try an MP4 (H.264, AAC) under 2 minutes 20 seconds.'));
            }
            usleep((int) config('social.poll_ms', 1500) * 1000);
        }

        throw new RuntimeException('X is still processing the video.');
    }
}
