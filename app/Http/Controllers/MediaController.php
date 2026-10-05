<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Media library files. The business sees its own through the portal; networks fetch them from a
 * short-lived signed link while publishing (the signature is checked by the `signed` middleware).
 */
class MediaController extends Controller
{
    public function show(CurrentOrganization $current, string $asset): StreamedResponse
    {
        return $this->stream(MediaAsset::query()->forOrganization($current->get())->where('ulid', $asset)->firstOrFail(), 'private, max-age=3600');
    }

    public function public(string $asset): StreamedResponse
    {
        return $this->stream(MediaAsset::withoutGlobalScopes()->where('ulid', $asset)->firstOrFail(), 'public, max-age=600');
    }

    private function stream(MediaAsset $media, string $cache): StreamedResponse
    {
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);

        return Storage::disk($media->disk)->response($media->path, basename($media->path), [
            'Content-Type' => $media->mime,
            'Cache-Control' => $cache,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
