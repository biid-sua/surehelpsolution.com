<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * The files phones read to open SureHelp links in the app (API-16): Android's assetlinks.json and
 * Apple's apple-app-site-association. Built from config/mobile.php; empty until the app ids are set.
 */
class AppLinksController extends Controller
{
    public function android(): JsonResponse
    {
        $config = (array) config('mobile.app_links');
        $fingerprints = (array) $config['android_sha256'];

        return $this->json($fingerprints === [] ? [] : [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => (string) $config['android_package'], 'sha256_cert_fingerprints' => $fingerprints],
        ]]);
    }

    public function apple(): JsonResponse
    {
        $config = (array) config('mobile.app_links');
        $components = array_map(fn (string $path) => ['/' => $path], (array) $config['paths']);

        return $this->json([
            'applinks' => [
                'details' => array_map(fn (string $id) => ['appIDs' => [$id], 'components' => $components], (array) $config['ios_app_ids']),
            ],
            'webcredentials' => ['apps' => array_values((array) $config['ios_app_ids'])],
        ]);
    }

    /** @param array<mixed> $data */
    private function json(array $data): JsonResponse
    {
        return response()->json($data, 200, ['Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }
}
