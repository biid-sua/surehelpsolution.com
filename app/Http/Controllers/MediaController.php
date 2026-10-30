<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Services\Social\House\HouseSocial;
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

    /** SureHelp's own library in the admin console (D104). */
    public function house(CurrentOrganization $current, HouseSocial $house, string $asset): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermissionIn('social.view'), 403);
        $current->set($house->organization());

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
