<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\AuthorizationFailureLogger;
use App\Http\Middleware\AccountGate;
use App\Http\Middleware\EnsureApiUserIsActive;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\Idempotent;
use App\Http\Middleware\RedirectGuestsToHome;
use App\Http\Middleware\RequireFeature;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'auth.home' => RedirectGuestsToHome::class,
            'force.password.change' => ForcePasswordChange::class,
            'api.active' => EnsureApiUserIsActive::class,
            'tenant' => ResolveOrganization::class,
            'account.gate' => AccountGate::class,
            'feature' => RequireFeature::class,
            'idempotent' => Idempotent::class,
        ]);

        $middleware->append(SecurityHeaders::class);

        // Guests are sent to the sign-in page; the API never redirects, it answers 401.
        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*') ? null : route('login'));
        // Signed-in people opening a guest page (sign in, forgot password) go to their portal.
        $middleware->redirectUsersTo(fn ($request) => $request->user()?->homeUrl() ?? route('home'));

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Order matters: the logger only observes (returns null), the renderer answers /api requests.
        $exceptions->render(new AuthorizationFailureLogger);
        $exceptions->render(new ApiExceptionRenderer);
        // An old or altered appointment link from a customer email (CAL-09): explain, don't just say 403.
        $exceptions->render(fn (InvalidSignatureException $e, $request) => $request->routeIs('appointments.manage')
            ? response()->view('customer.link-expired', status: 403) : null);

        // /api always answers in JSON, even when a client forgets the Accept header.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
