<?php

namespace App\Http\Middleware;

use App\Support\Security\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response (spec §58), and a Content-Security-Policy on pages
 * (D67). The nonce is created before the page renders, so Vite, Livewire and our inline scripts get it.
 */
class SecurityHeaders
{
    public function __construct(private readonly ContentSecurityPolicy $csp) {}

    public function handle(Request $request, Closure $next): Response
    {
        $mode = (string) config('csp.mode', 'enforce');
        if ($mode !== 'off' && ! $request->is('api/*')) {
            Vite::useCspNonce();
        }

        $response = $next($request);

        if ($mode !== 'off' && $this->isPage($response)
            && ! $response->headers->has('Content-Security-Policy') && ! $response->headers->has('Content-Security-Policy-Report-Only')) {
            $response->headers->set($mode === 'report' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $this->csp->header());
        }

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        // HSTS only over HTTPS, so local HTTP development isn't locked out.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // Authenticated API data must never be cached by browsers or proxies.
        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    /** An HTML page (Laravel sets the content type of a rendered view only when sending it). */
    private function isPage(Response $response): bool
    {
        $type = $response->headers->get('Content-Type');

        return $type === null
            ? $response instanceof \Illuminate\Http\Response
            : str_contains($type, 'text/html');
    }
}
