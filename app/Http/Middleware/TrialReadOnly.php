<?php

namespace App\Http\Middleware;

use App\Services\Billing\Trials;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a self-serve trial ended without a plan, the business can look but not change (D97): pages
 * and the app still open, but anything that saves is refused, except billing and data exports so
 * they can choose a plan or take their data. Livewire actions are held back in TrialReadOnly::blocks().
 */
class TrialReadOnly
{
    public const MESSAGE = 'Your free trial has ended, so your account is read-only. Choose a plan under Billing to make changes.';

    /** Routes that still save: choosing a plan, paying, and taking your data. */
    private const OPEN = ['app.billing*', 'app.settings.privacy*', 'api/v1/client/billing/*', 'api/v1/client/inbox/conversations/*/read'];

    public function __construct(private readonly CurrentOrganization $current, private readonly Trials $trials) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organization = $this->current->get();
        if ($request->isMethodSafe() || ! $organization || $request->routeIs(...self::OPEN) || $request->is(...self::OPEN)
            || ! $this->trials->ended($organization)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 403);
        }

        return redirect()->route('app.billing')->with('trial_locked', self::MESSAGE);
    }

    /** Livewire: actions on business-portal screens, other than paging and refreshing, wait for a plan. */
    public static function blocks(object $component, string $method): bool
    {
        $class = $component::class;
        if (! str_starts_with($class, 'App\\Livewire\\Client\\')
            || str_starts_with($class, 'App\\Livewire\\Client\\Billing\\') || str_starts_with($class, 'App\\Livewire\\Client\\Settings\\Privacy')
            || in_array($method, ['$refresh', '$set', '$toggle', '$commit', '__dispatch', 'gotoPage', 'nextPage', 'previousPage', 'setPage', 'resetPage'], true)) {
            return false;
        }
        $organization = app(CurrentOrganization::class)->get();

        return $organization !== null && auth()->user()?->isClient() && app(Trials::class)->ended($organization);
    }
}
