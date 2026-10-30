<?php

namespace App\Services\Ai;

use App\Exceptions\AiUnavailable;
use App\Models\MediaAsset;
use App\Models\Organization;
use App\Support\Audit\Audit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pictures made by ChatGPT for social posts (D104). Claude doesn't make images, so this needs an OpenAI
 * key and a picture model set under Admin › Connections. The picture is saved to the business's media
 * library like an upload, marked as AI-made with its prompt.
 */
class AiImages
{
    public function __construct(private readonly Audit $audit) {}

    public function available(): bool
    {
        return filled(config('ai.openai.api_key')) && filled(config('ai.openai.image_model'));
    }

    /**
     * @param  string  $size  WIDTHxHEIGHT; 1024x1024 suits every network
     *
     * @throws AiUnavailable
     */
    public function generate(Organization $organization, string $prompt, string $size = '1024x1024', ?string $alt = null): MediaAsset
    {
        if (! $this->available()) {
            throw new AiUnavailable('Connect ChatGPT and choose a picture model under Admin › Connections to make pictures.');
        }

        try {
            $response = Http::withToken((string) config('ai.openai.api_key'))->acceptJson()->timeout(180)
                ->post('https://api.openai.com/v1/images/generations', [
                    'model' => config('ai.openai.image_model'), 'prompt' => mb_substr($prompt, 0, 4000), 'size' => $size, 'n' => 1,
                ]);
        } catch (ConnectionException $e) {
            throw new AiUnavailable('Couldn\'t reach OpenAI: '.$e->getMessage(), previous: $e);
        }
        if ($response->failed()) {
            throw new AiUnavailable('ChatGPT couldn\'t make the picture: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        $base64 = $response->json('data.0.b64_json');
        $bytes = $base64 ? base64_decode((string) $base64, true) : null;
        if (! $bytes && ($url = $response->json('data.0.url'))) {
            $bytes = rescue(fn () => Http::timeout(60)->get($url)->body(), null, report: false);
        }
        if (! $bytes) {
            throw new AiUnavailable('ChatGPT returned no picture.');
        }

        $info = @getimagesizefromstring($bytes) ?: [null, null, 'mime' => 'image/png'];
        $extension = match ($info['mime'] ?? 'image/png') { 'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => 'png' };
        $path = 'social/'.$organization->ulid.'/'.Str::ulid().'.'.$extension;
        Storage::disk('local')->put($path, $bytes);

        $asset = MediaAsset::create([
            'organization_id' => $organization->id, 'disk' => 'local', 'path' => $path,
            'original_name' => 'AI picture '.now()->format('Y-m-d H:i').'.'.$extension, 'mime' => $info['mime'] ?? 'image/png',
            'size_bytes' => strlen($bytes), 'width' => $info[0], 'height' => $info[1],
            'alt_text' => $alt ? Str::limit($alt, 500, '') : null,
            'source' => MediaAsset::SOURCE_AI, 'ai_prompt' => $prompt,
        ]);
        $this->audit->record('media.ai_generated', $asset, new: ['model' => config('ai.openai.image_model')], organization: $organization, label: $asset->original_name);

        return $asset;
    }
}
