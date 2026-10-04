<?php

use App\Http\Middleware\EnsureApiUserIsActive;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\RedirectGuestsToHome;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
        ]);

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
