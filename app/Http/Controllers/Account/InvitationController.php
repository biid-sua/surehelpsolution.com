<?php

namespace App\Http\Controllers\Account;

use App\Actions\Team\AcceptInvitation;
use App\Http\Controllers\Controller;
use App\Models\OrganizationInvitation;
use App\Services\Account\LegalDocuments;
use App\Services\Account\SignIn;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * Where an invited person lands from the email: set a name and password, accept the terms, join.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token, LegalDocuments $legal): View
    {
        $invitation = OrganizationInvitation::findByToken($token)?->load('organization:id,name', 'inviter:id,name');

        return view('auth.invitation', [
            'invitation' => $invitation?->isPending() ? $invitation : null,
            'token' => $token,
            'documents' => array_filter(config('account.legal', []), fn (array $d) => in_array('client', $d['roles'], true)),
            'signedInAs' => $request->user(),
        ]);
    }

    public function accept(Request $request, string $token, AcceptInvitation $accept, SignIn $signIn): RedirectResponse
    {
        $invitation = OrganizationInvitation::findByToken($token);
        abort_unless($invitation?->isPending(), 410, 'This invitation is no longer valid. Ask the business owner to invite you again.');
        abort_if($request->user() !== null, 409, 'Sign out first: this invitation creates a new account.');

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'accept' => ['accepted'],
        ], ['accept.accepted' => 'Please tick the box to accept the terms.']);

        $user = $accept->handle($invitation, (string) $request->input('name'), (string) $request->input('password'), $request);

        return redirect()->to($signIn->complete($user, $request, 'invitation'))
            ->with('status', 'Welcome to '.$invitation->organization->name.' on SureHelp.');
    }
}
