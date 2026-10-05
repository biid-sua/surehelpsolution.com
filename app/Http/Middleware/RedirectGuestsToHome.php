<?php

namespace App\Http\Middleware;

use App\Services\Account\SignIn;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal pages need a signed-in, active account. Guests go to the sign-in page and come back
 * to where they were headed; a switched-off account is signed out with an explanation.
 */
class RedirectGuestsToHome
{
    public function __construct(private readonly SignIn $signIn) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return redirect()->guest(route('login'));
        }

        if (! auth()->user()->is_active) {
            $this->signIn->logout($request, 'Your account is switched off. Please contact the person who manages your SureHelp account.');

            return redirect()->route('login');
        }

        return $next($request);
    }
}
