<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Support\Ui\Themes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saves a person's colour theme (D59). `default` clears their own choice, so they follow the
 * default an administrator picks again.
 */
class ThemeController extends Controller
{
    public function update(Request $request, Themes $themes): JsonResponse
    {
        $data = $request->validate(['theme' => ['required', 'string', Rule::in($themes->keys())]]);
        $themes->choose($request->user(), $data['theme']);
        $saved = $themes->payload($request->user());

        return response()->json(['theme' => $saved['key'], 'own' => $saved['own']]);
    }
}
