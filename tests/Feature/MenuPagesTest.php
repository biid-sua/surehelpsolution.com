<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every item in the site's header and footer menus opens a real page (config/pages.php).
 */
class MenuPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_menu_page_opens_and_the_menus_link_to_them(): void
    {
        foreach (config('pages') as $slug => $page) {
            $this->get(route('pages.show', $slug))->assertOk()->assertSee($page['title'], false);
        }

        $home = $this->get(route('home'))->assertOk()->getContent();
        foreach (array_keys(config('pages')) as $slug) {
            $this->assertStringContainsString(route('pages.show', $slug), $home, "No menu link to {$slug}");
        }
        // Only the drop-down toggles may use "#".
        $this->assertSame(0, preg_match_all('~<a(?![^>]*data-bs-toggle)[^>]*href="#"~', preg_replace('~<!--.*?-->~s', '', $home)));
    }

    public function test_unknown_pages_are_not_found(): void
    {
        $this->get('/not-a-real-page')->assertNotFound();
    }
}
