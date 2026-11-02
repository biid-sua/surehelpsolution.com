<?php

namespace Tests\Feature;

use App\Support\Ui\SiteDesigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Hands Full", the missed-call game (D122): a public page whose branding and buttons come from
 * config/game.php, listed in the sitemap, and switchable off.
 */
class GameTest extends TestCase
{
    use RefreshDatabase;

    public function test_anyone_can_play_with_the_configured_branding(): void
    {
        config(['game.brand.receptionist' => 'Maya', 'game.brand.colors.accent' => '#ffaa00']);

        $page = $this->get('/missed-call-game')->assertOk()
            ->assertSee('Hands')->assertSee('Maya')->assertSee('--accent:#ffaa00', false)
            ->assertSee('data-settings=', false)->assertSee('data-start', false);

        // The buttons at the end carry the tracking tags.
        $page->assertSee(route('signup').'?utm_source=game', false);
        $page->assertSee('utm_campaign=hands-full', false);
    }

    public function test_the_settings_handed_to_the_game_are_valid_json(): void
    {
        $html = $this->get(route('game'))->assertOk()->getContent();
        $this->assertSame(1, preg_match("/data-settings='([^']+)'/", $html, $m));
        $settings = json_decode(html_entity_decode($m[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(60, $settings['total']);
        $this->assertSame(20, $settings['offerAfter']);
        $this->assertCount(2, $settings['cta']);
        $this->assertSame(route('game'), $settings['share']);
    }

    public function test_it_is_in_the_sitemap_and_linked_from_the_modern_home_page(): void
    {
        $this->get(route('seo.sitemap'))->assertSee(route('game'));

        app(SiteDesigns::class)->set('modern');
        $this->get('/')->assertSee(route('game'), false)->assertSee('missed-call game');
    }

    public function test_it_can_be_switched_off(): void
    {
        config(['game.enabled' => false]);

        $this->get(route('game'))->assertNotFound();
        $this->get(route('seo.sitemap'))->assertDontSee(route('game'));
    }
}
