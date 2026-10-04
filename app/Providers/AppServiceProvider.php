<?php

namespace App\Providers;

use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\RoleMiddleware;
use App\Models\Organization;
use App\Models\User;
use App\Services\Calls\CallOutcomes;
use App\Support\Authorization\RoleCatalog;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant context per request / queued job.
        $this->app->scoped(CurrentOrganization::class);
        $this->app->scoped(CallOutcomes::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every permission from config/authorization.php is answered by one
        // organization-aware check (docs/permissions.md). Pass an Organization as
        // the argument, or the current tenant context is used.
        Gate::before(function (User $user, string $ability, array $arguments) {
            if (! app(RoleCatalog::class)->isPermission($ability)) {
                return null; // not a catalogue permission: let policies decide
            }

            $organization = collect($arguments)->first(fn ($argument) => $argument instanceof Organization);

            return $user->hasPermissionIn($ability, $organization);
        });

        // Re-apply tenant resolution on Livewire component updates (/livewire/update),
        // which otherwise skip route middleware.
        Livewire::addPersistentMiddleware([
            ResolveOrganization::class,
            RoleMiddleware::class,
            Authorize::class,
        ]);

        // Brute-force protection for web and API login: 5 attempts per minute
        // per email + IP, and 20 per minute per IP across all emails.
        // General API budget (spec §50): per user when signed in, per IP otherwise.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ? 'user:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip()));

        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });
    }
}
