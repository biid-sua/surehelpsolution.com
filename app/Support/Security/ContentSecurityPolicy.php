<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\Vite;

/**
 * Builds the Content-Security-Policy header (D67). Scripts run only with this request's nonce, from
 * this site or from the website's CDNs, so injected <script> tags and inline event handlers don't run.
 * Alpine (inside Livewire) evaluates its attributes, which needs 'unsafe-eval'; inline style attributes
 * stay allowed because the design uses them for colours and sizes.
 */
class ContentSecurityPolicy
{
    public function header(): string
    {
        $nonce = Vite::cspNonce();
        $cdns = (array) config('csp.website_cdns', []);
        $hot = $this->viteDevServer();

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", $nonce ? "'nonce-{$nonce}'" : null, "'unsafe-eval'", ...$cdns, ...$hot],
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com', ...$cdns, ...$hot],
            'font-src' => ["'self'", 'data:', 'https://fonts.gstatic.com', ...$cdns],
            'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
            'media-src' => ["'self'", 'blob:', 'https:'],
            'connect-src' => ["'self'", ...(array) config('csp.connect', []), ...$hot, ...array_map(fn ($u) => preg_replace('~^http~', 'ws', $u), $hot)],
            'frame-src' => ["'self'", ...(array) config('csp.frames', [])],
            'worker-src' => ["'self'", 'blob:'],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];
        if (config('csp.report')) {
            $directives['report-uri'] = [route('csp.report', absolute: false)];
        }

        return collect($directives)
            ->map(fn (array $sources, string $name) => $name.' '.implode(' ', array_unique(array_filter($sources))))
            ->implode('; ');
    }

    /** @return list<string> the Vite dev server while `npm run dev` runs, so local pages keep working */
    private function viteDevServer(): array
    {
        $file = public_path('hot');
        if (! is_file($file)) {
            return [];
        }
        $url = rtrim(trim((string) file_get_contents($file)), '/');

        return $url !== '' ? [$url] : [];
    }
}
