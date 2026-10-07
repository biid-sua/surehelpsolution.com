<?php

namespace App\Http\Controllers;

use App\Models\BusinessProfile;
use App\Models\Organization;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A business's logo. Public (it appears on pages customers open), long-cached; the URL changes with each upload.
 */
class BusinessLogoController extends Controller
{
    public function __invoke(string $business): StreamedResponse
    {
        $path = BusinessProfile::withoutGlobalScopes()
            ->whereIn('organization_id', Organization::query()->where('ulid', $business)->select('id'))->value('logo_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
