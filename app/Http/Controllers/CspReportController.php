<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Where browsers report what the Content-Security-Policy blocked (D67): written to the security log so
 * a page broken by the policy, or an injection attempt, shows up. Only the fields needed to find it.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $body = json_decode((string) $request->getContent(), true);
        $report = is_array($body) ? ($body['csp-report'] ?? $body[0]['body'] ?? $body) : [];

        if (is_array($report)) {
            $pick = fn (string ...$keys) => collect($keys)->map(fn ($k) => $report[$k] ?? null)->first(fn ($v) => is_string($v) && $v !== '');
            Log::channel('security')->warning('csp.violation', [
                'page' => Str::limit((string) $pick('document-uri', 'documentURL'), 300),
                'directive' => Str::limit((string) $pick('effective-directive', 'violated-directive', 'effectiveDirective'), 60),
                'blocked' => Str::limit((string) $pick('blocked-uri', 'blockedURL'), 300),
                'source' => Str::limit((string) $pick('source-file', 'sourceFile'), 300),
                'line' => $report['line-number'] ?? $report['lineNumber'] ?? null,
                'ip' => $request->ip(),
            ]);
        }

        return response()->noContent();
    }
}
