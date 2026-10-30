<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The Content-Security-Policy (D67): on every page, a fresh nonce shared by the page's own scripts,
 * report-only and off switches, no header on the API, and violation reports in the security log.
 */
class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function nonce(string $header): string
    {
        $this->assertSame(1, preg_match("/'nonce-([A-Za-z0-9+\/=_-]+)'/", $header, $m), $header);

        return $m[1];
    }

    public function test_pages_carry_a_policy_and_every_inline_script_has_its_nonce(): void
    {
        foreach (['/', '/login', '/privacy-policy', '/start'] as $path) {
            $response = $this->get($path)->assertOk();
            $header = (string) $response->headers->get('Content-Security-Policy');
            $nonce = $this->nonce($header);

            $this->assertStringContainsString("object-src 'none'", $header);
            $this->assertStringContainsString("frame-ancestors 'self'", $header);
            $this->assertStringContainsString("base-uri 'self'", $header);
            $this->assertStringContainsString('report-uri /api/csp-report', $header);
            $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $header);

            // Every executable inline script on the page carries this request's nonce; no inline handlers.
            preg_match_all('/<script(?![^>]*\bsrc=)(?![^>]*application\/ld\+json)([^>]*)>/i', $response->getContent(), $scripts);
            if ($path === '/') {
                $this->assertNotEmpty($scripts[0], 'the website has inline scripts to check');
            }
            foreach ($scripts[1] as $attributes) {
                $this->assertStringContainsString("nonce=\"{$nonce}\"", $attributes, "{$path}: <script{$attributes}>");
            }
            $this->assertDoesNotMatchRegularExpression('/\son(click|load|error|change|submit)="/i', $response->getContent(), $path);
        }

        // A new nonce for each request.
        $this->assertNotSame($this->nonce((string) $this->get('/login')->headers->get('Content-Security-Policy')),
            $this->nonce((string) $this->get('/login')->headers->get('Content-Security-Policy')));
    }

    public function test_report_only_and_off_modes_and_no_policy_on_the_api(): void
    {
        config(['csp.mode' => 'report']);
        $this->get('/login')->assertHeaderMissing('Content-Security-Policy')->assertHeader('Content-Security-Policy-Report-Only');

        config(['csp.mode' => 'off']);
        $this->get('/login')->assertHeaderMissing('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');

        config(['csp.mode' => 'enforce']);
        $this->getJson('/api/v1/plans')->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_browsers_report_what_was_blocked_to_the_security_log(): void
    {
        Log::shouldReceive('channel')->with('security')->once()->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'csp.violation'
            && $context['directive'] === 'script-src-elem' && $context['blocked'] === 'https://evil.test/x.js' && $context['page'] === 'https://surehelp.test/app');

        $this->call('POST', '/api/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode(['csp-report' => [
            'document-uri' => 'https://surehelp.test/app', 'effective-directive' => 'script-src-elem', 'blocked-uri' => 'https://evil.test/x.js',
        ]]))->assertNoContent();
    }
}
