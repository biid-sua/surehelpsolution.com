<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Inbox\TextedPictures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Shows a picture texted to a business (D62), only to people who can read its Inbox. */
class InboxMediaController extends Controller
{
    public function __invoke(Request $request, string $organization, string $file): Response
    {
        $business = Organization::query()->where('ulid', $organization)->first();
        abort_unless($business && $request->user()->hasPermissionIn('messages.view', $business), 404);
        abort_unless(preg_match('/^[0-9a-z]{26}\.(jpg|png|gif|webp|heic)$/', $file) === 1, 404);
        $path = TextedPictures::path($business->ulid, $file);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
