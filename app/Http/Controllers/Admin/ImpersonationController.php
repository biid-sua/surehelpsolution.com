<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Start and stop "view as client" (spec ADM-05).
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, User $user, Impersonation $impersonation): RedirectResponse
    {
        $impersonation->start($request->user(), $user, $request);

        return redirect()->route('app.dashboard');
    }

    public function stop(Request $request, Impersonation $impersonation): RedirectResponse
    {
        abort_unless($impersonation->active($request), 404);

        return redirect()->to($impersonation->stop($request) ?? route('login'));
    }
}
