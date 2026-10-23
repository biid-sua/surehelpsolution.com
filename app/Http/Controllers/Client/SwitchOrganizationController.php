<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Multi-location (D89): work in another business this person belongs to.
 */
class SwitchOrganizationController extends Controller
{
    public function __invoke(Request $request, string $organization): RedirectResponse
    {
        $target = $request->user()->activeOrganizations()->firstWhere('ulid', $organization) ?? abort(404);
        $request->session()->put(User::CURRENT_ORGANIZATION, $target->ulid);

        $request->session()->flash('status', 'Now working in '.$target->name.'.');

        return new RedirectResponse(route('app.dashboard'));
    }
}
