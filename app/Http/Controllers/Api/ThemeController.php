<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\Ui\Themes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Colour themes for the mobile app (D59). The same choice as on the web: saved on the account,
 * so the app and every browser show the same theme.
 */
class ThemeController extends Controller
{
    public function __construct(private readonly Themes $themes) {}

    /** GET /themes: every theme with its colours, and the one this person sees. */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'themes' => $this->themes->catalog(),
            'current' => $this->themes->payload($request->user()),
        ]);
    }

    /** PUT /user/theme: `theme` = a theme key, or `default` to follow the SureHelp default again. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['theme' => ['required', 'string', Rule::in($this->themes->keys())]]);
        $this->themes->choose($request->user(), $data['theme']);

        return ApiResponse::success(['theme' => $this->themes->payload($request->user())],
            $data['theme'] === 'default' ? 'Using the SureHelp default theme.' : 'Theme saved.');
    }
}
