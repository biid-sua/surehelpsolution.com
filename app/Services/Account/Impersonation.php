<?php

namespace App\Services\Account;

use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "View as client" (spec ADM-05). SureHelp staff with `users.impersonate` sign in as a business user
 * to see exactly what they see. A banner shows it on every page; everything done meanwhile is
 * audited under the client with the staff member recorded as `impersonator_id`; the staff member's
 * own idle timeout applies; account pages (password, two-step sign-in) are off limits.
 */
class Impersonation
{
    public const KEY = 'impersonator_id';

    public function __construct(private readonly Audit $audit) {}

    public function canImpersonate(User $staff, User $target): bool
    {
        return $staff->isAdmin()
            && $staff->hasPermissionIn('users.impersonate')
            && $target->isClient()
            && $target->is_active
            && $target->primaryOrganization() !== null;
    }

    public function start(User $staff, User $target, Request $request): void
    {
        abort_unless($this->canImpersonate($staff, $target), 403, 'You can only view as an active business user.');
        abort_if($this->active($request), 409, 'Stop viewing as the current person first.');

        $this->audit->record('impersonation.started', $target, new: ['by' => $staff->email], organization: $target->primaryOrganization(), actor: $staff);

        Auth::login($target);
        $request->session()->regenerate();
        $request->session()->put([
            self::KEY => $staff->id,
            'impersonator_epoch' => (int) $staff->session_epoch,
            'impersonator_return' => url()->previous(),
            'auth.epoch' => (int) $target->session_epoch,
            'auth.last_activity' => now()->timestamp,
            'legal.ok' => 'impersonating',
        ]);
    }

    public function stop(Request $request): ?string
    {
        $staffId = $request->session()->get(self::KEY);
        $staff = $staffId ? User::find($staffId) : null;
        $client = $request->user();
        $return = $request->session()->get('impersonator_return');

        if (! $staff || ! $staff->is_active || (int) $staff->session_epoch !== (int) $request->session()->get('impersonator_epoch')) {
            app(SignIn::class)->logout($request, 'Viewing as a client ended. Please sign in again.');

            return null;
        }

        $this->audit->record('impersonation.ended', $client, organization: $client?->primaryOrganization(), actor: $staff);
        $request->session()->forget([self::KEY, 'impersonator_epoch', 'impersonator_return', 'legal.ok']);
        Auth::login($staff);
        $request->session()->regenerate();
        $request->session()->put(['auth.epoch' => (int) $staff->session_epoch, 'auth.last_activity' => now()->timestamp]);

        return is_string($return) && str_contains($return, '/admin') ? $return : route('admin.home');
    }

    public function active(Request $request): bool
    {
        return $request->hasSession() && $request->session()->has(self::KEY);
    }

    public function impersonator(Request $request): ?User
    {
        return $this->active($request) ? User::find($request->session()->get(self::KEY)) : null;
    }
}
