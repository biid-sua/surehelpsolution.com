<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Menu links change only the page, not the whole window (D106). The browser keeps the policy of the
 * first page it loaded, whose nonce the next pages don't share, so portal pages must not depend on
 * inline scripts, and the head must be the same on every page.
 */
class PortalNavigationTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> the attributes of each executable inline script */
    private function inlineScripts(TestResponse $response): array
    {
        preg_match_all('/<script(?![^>]*\bsrc=)(?![^>]*application\/ld\+json)([^>]*)>/i', (string) $response->getContent(), $scripts);

        return $scripts[1];
    }

    private function headOf(TestResponse $response): string
    {
        $html = (string) $response->getContent();

        return substr($html, 0, (int) strpos($html, '</head>'));
    }

    public function test_every_portal_changes_pages_in_place_without_inline_scripts(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $this->enableTwoFactor($admin);
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($owner);
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);
        $this->enableTwoFactor($agent);

        foreach ([[$admin, route('admin.home'), route('admin.users')], [$owner, route('app.dashboard'), route('app.calendar')], [$agent, route('agent.home'), route('agent.calls')]] as [$user, $first, $second]) {
            $page = $this->actingAs($user)->get($first)->assertOk();
            $this->assertMatchesRegularExpression('/<a href="[^"]+" wire:navigate[^>]*>/', (string) $page->getContent(), 'menu links change the page in place');
            $this->assertStringContainsString('id="sidebar-nav"', (string) $page->getContent());
            $this->assertSame([], $this->inlineScripts($page), $first.' has no inline script that a later page change would block');
            $this->assertStringNotContainsString('nonce=', $this->headOf($page), 'our own files carry no nonce');

            // The head (styles, scripts, theme) is the same from page to page, so it is kept, not reloaded.
            $next = $this->actingAs($user)->get($second)->assertOk();
            $strip = fn (string $head) => preg_replace(['/<title>.*?<\/title>/s', '/<meta name="csrf-token"[^>]*>/'], '', $head);
            $this->assertSame($strip($this->headOf($page)), $strip($this->headOf($next)));
        }

        // The agent's phone is kept alive across page changes, so a live call isn't cut off.
        $this->actingAs($agent)->get(route('agent.home'))->assertSee('x-persist="softphone"', false);
    }

    public function test_the_theme_is_set_before_paint_from_a_file(): void
    {
        $this->get('/login')->assertOk()->assertSee('/js/theme-boot.js?v=', false);
        $this->assertFileExists(public_path('js/theme-boot.js'));
    }

    public function test_a_message_from_the_last_request_is_shown_without_an_inline_script(): void
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($owner);

        $page = $this->actingAs($owner)->withSession(['toast' => ['type' => 'success', 'message' => 'Saved & done']])->get(route('app.dashboard'))->assertOk();
        $page->assertSee('data-flash="{&quot;type&quot;:&quot;success&quot;,&quot;message&quot;:&quot;Saved &amp; done&quot;}"', false);
        $this->assertSame([], $this->inlineScripts($page));
    }
}
