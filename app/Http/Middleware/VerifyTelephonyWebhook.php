<?php

namespace App\Http\Middleware;

use App\Services\Telephony\TelephonyProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Only the telephony vendor may drive calls: every webhook must carry its signature (D60). */
class VerifyTelephonyWebhook
{
    public function __construct(private readonly TelephonyProvider $provider) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->provider->isGenuine($request), 403);

        return $next($request);
    }
}
