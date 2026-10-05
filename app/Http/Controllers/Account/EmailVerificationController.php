<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Confirming an email address with the signed link from VerifyEmail. Works without being signed in,
 * so it can be opened on a phone.
 */
class EmailVerificationController extends Controller
{
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403, 'This link doesn\'t match the account\'s email address.');

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            app(Audit::class)->record('auth.email_verified', $user, actor: $user);
        }

        $message = 'Thanks, your email address is confirmed.';

        return $request->user()
            ? redirect()->route('account.profile')->with('status', $message)
            : redirect()->route('login')->with('status', $message);
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', 'We sent a confirmation link to '.$user->email.'.');
    }
}
