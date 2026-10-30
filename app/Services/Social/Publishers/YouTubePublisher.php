<?php

namespace App\Services\Social\Publishers;

use App\Enums\SocialNetwork;
use App\Exceptions\SocialPostRejected;
use App\Models\SocialAccount;
use App\Services\Social\Connectors\GoogleBusinessConnector;
use App\Services\Social\Contracts\SocialPublisher;
use App\Services\Social\Data\PublishRequest;
use App\Services\Social\Data\PublishResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * YouTube (D104): uploads the post's video with a resumable upload. The first line of the text is the
 * title, the rest the description. Vertical videos under three minutes show as Shorts on their own.
 */
class YouTubePublisher implements SocialPublisher
{
    private const UPLOAD = 'https://www.googleapis.com/upload/youtube/v3/videos';

    public function network(): SocialNetwork
    {
        return SocialNetwork::YouTube;
    }

    public function publish(SocialAccount $account, string $accessToken, PublishRequest $request): PublishResult
    {
        $video = $request->video() ?? throw new SocialPostRejected('YouTube posts need a video.');
        $lines = preg_split('/\R/', trim($request->text)) ?: [''];
        $title = mb_substr(trim(str_replace(['<', '>'], '', (string) array_shift($lines))), 0, 100) ?: 'Video';
        $description = trim(implode("\n", $lines).($request->link && ! str_contains($request->text, $request->link) ? "\n\n".$request->link : ''));
        $disk = Storage::disk($video['disk']);
        $size = $video['size'] ?: (int) $disk->size($video['path']);

        // 1. Start the upload with the video's details; Google answers with an upload address.
        $session = GoogleBusinessConnector::ok(Http::withToken($accessToken)
            ->withHeaders(['X-Upload-Content-Type' => $video['mime'], 'X-Upload-Content-Length' => (string) $size])
            ->post(self::UPLOAD.'?uploadType=resumable&part=snippet,status', [
                'snippet' => ['title' => $title, 'description' => mb_substr(str_replace(['<', '>'], '', $description), 0, 5000), 'categoryId' => '22'],
                'status' => ['privacyStatus' => 'public', 'selfDeclaredMadeForKids' => false],
            ]))->header('Location');

        // 2. Send the file.
        $stream = $disk->readStream($video['path']);
        try {
            $json = GoogleBusinessConnector::ok(Http::withToken($accessToken)->timeout(600)
                ->withBody(stream_get_contents($stream) ?: '', $video['mime'])->put($session))->json();
        } finally {
            is_resource($stream) && fclose($stream);
        }

        return new PublishResult((string) $json['id'], 'https://www.youtube.com/watch?v='.$json['id']);
    }
}
