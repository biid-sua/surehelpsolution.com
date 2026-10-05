<?php

namespace App\Http\Middleware;

use App\Services\Account\LegalDocuments;
use App\Services\Account\SignIn;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account rules for every signed-in web request (D8, D24), including Livewire updates:
 *  1. Signed out everywhere (password reset, "sign out other devices"): this session ends.
 *  2. Idle too long (staff and agents, 30 min): this session ends.
 *  3. Staff and agents without two-step sign-in set it up before anything else.
 *  4. Everyone accepts the current Terms / Privacy Policy (and DPA for businesses).
 */
class AccountGate
{
    /** Pages always reachable, so people can finish what the gate asks of them. */
    private const OPEN = ['account.*', 'auth.logout', 'password.change', 'password.change.update', 'verification.*', 'legal.*'];

    public function __construct(private readonly SignIn $signIn, private readonly LegalDocuments $legal) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $request->hasSession()) {
            return $next($request);
        }
        $session = $request->session();

        // 1. Sessions from before "sign out everywhere" are no longer valid.
        $epoch = $session->get('auth.epoch');
        if ($epoch === null) {
            $session->put('auth.epoch', (int) $user->session_epoch);
        } elseif ((int) $epoch !== (int) $user->session_epoch) {
            return $this->end($request, 'You were signed out because your password changed or you signed out of all devices.');
        }

        // 2. Idle timeout.
        $timeout = config('account.idle_timeout.'.$user->role);
        $last = $session->get('auth.last_activity');
        if ($timeout && $last && now()->timestamp - (int) $last > $timeout * 60) {
            return $this->end($request, "You were signed out after {$timeout} minutes without activity. Please sign in again.");
        }
        if (! $this->isBackgroundPoll($request)) {
            $session->put('auth.last_activity', now()->timestamp);
        }

        if ($request->routeIs(...self::OPEN)) {
            return $next($request);
        }

        // 3. Mandatory two-step sign-in, set at sign-in for people who must have it.
        if ($session->get('two_factor.setup_required')) {
            if ($user->hasTwoFactor()) {
                $session->forget('two_factor.setup_required');
            } else {
                return $this->send($request, 'account.security', 'Set up two-step sign-in to continue. It takes a minute with an authenticator app.');
            }
        }

        // 4. Current legal documents, checked once per session and again when versions change.
        $fingerprint = $this->legal->fingerprint($user);
        if ($session->get('legal.ok') !== $fingerprint) {
            if ($this->legal->pendingFor($user) !== []) {
                return $this->send($request, 'account.terms');
            }
            $session->put('legal.ok', $fingerprint);
        }

        return $next($request);
    }

    private function end(Request $request, string $notice): Response
    {
        $this->signIn->logout($request, $notice);

        // A Livewire or JSON request can't follow a redirect to a page; 419 makes Livewire reload, landing on sign-in.
        return $request->expectsJson() || $request->hasHeader('X-Livewire')
            ? response()->json(['message' => $notice], 419)
            : redirect()->route('login');
    }

    private function send(Request $request, string $route, ?string $status = null): Response
    {
        if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
            return response()->json(['message' => $status ?? 'Please finish setting up your account.', 'redirect' => route($route)], 403);
        }
        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route($route)->with('status', $status);
    }

    /** A Livewire poll (wire:poll) only refreshes; it isn't someone using the page. */
    private function isBackgroundPoll(Request $request): bool
    {
        if (! $request->hasHeader('X-Livewire')) {
            return false;
        }

        foreach ((array) $request->input('components', []) as $component) {
            if (! empty($component['updates'])) {
                return false;
            }
            foreach ((array) ($component['calls'] ?? []) as $call) {
                if (($call['method'] ?? null) !== '$refresh') {
                    return false;
                }
            }
        }

        return true;
    }
}
