<?php

namespace App\Providers;

use App\Http\Middleware\AccountGate;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\RoleMiddleware;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Providers\ClaudeProvider;
use App\Services\Billing\BillingSettings;
use App\Services\Billing\Entitlements;
use App\Services\Billing\FeatureAccess;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Services\Billing\Gateways\PayoneerGateway;
use App\Services\Calls\CallOutcomes;
use App\Services\Rules\BusinessRules;
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

        // Call outcomes are memoised per request (call lists ask about every row).
        $this->app->scoped(CallOutcomes::class);
        $this->app->scoped(BusinessRules::class);
        $this->app->scoped(BillingSettings::class);
        $this->app->scoped(Entitlements::class);
        $this->app->scoped(FeatureAccess::class);
        // AI vendor behind one interface (spec §33, D38).
        $this->app->singleton(AiProvider::class, ClaudeProvider::class);
        $this->app->bind(PaymentGateway::class, fn () => match (config('billing.gateway')) {
            default => $this->app->make(PayoneerGateway::class),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Livewire's script URL is root-relative. When the app runs in a sub-folder (a local XAMPP
        // install), the browser would ask the server root for it and every page's scripts would
        // fail; add the folder. At a domain root (production) the base path is empty: unchanged.
        if (! app()->runningInConsole() && ($base = request()->getBasePath()) !== '' && ! config('livewire.asset_url')) {
            config(['livewire.asset_url' => $base.'/livewire/livewire'.(config('app.debug') ? '' : '.min').'.js']);
        }

        // Lesson files (Agent University) can be larger than Livewire's 12 MB default. Each form
        // still validates its own limit and file types.
        config([
            'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.(max(12, (int) config('training.max_upload_mb')) * 1024)],
            'livewire.temporary_file_upload.max_upload_time' => 15,
        ]);

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
            AccountGate::class,
            ResolveOrganization::class,
            RoleMiddleware::class,
            Authorize::class,
        ]);

        // Brute-force protection for web and API login: 5 attempts per minute
        // per email + IP, and 20 per minute per IP across all emails.
        // General API budget (spec §50): per user when signed in, per IP otherwise.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ? 'user:'.$request->user()->getAuthIdentifier() : 'ip:'.$request->ip()));

        // Website chat (public): per visitor IP and widget, plus a ceiling per widget so one site can't flood a business.
        RateLimiter::for('chat', fn (Request $request) => [
            Limit::perMinute(20)->by('chat:'.$request->ip().':'.$request->route('key')),
            Limit::perMinute(300)->by('chat-widget:'.$request->route('key')),
        ]);

        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });
    }
}
