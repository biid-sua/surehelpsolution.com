<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Admin\Appearance;
use App\Models\Plan;
use App\Models\User;
use App\Support\Ui\SiteDesigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The home page's designs (D121): Classic stays live until an administrator switches to Modern in
 * Admin › Appearance, for the home page and the other public pages; until then Modern is only seen
 * through a signed, unindexed preview link, which keeps it on for the rest of the visit.
 */
class HomeDesignTest extends TestCase
{
    use RefreshDatabase;

    private const CLASSIC = 'Every Missed Call Is a Missed Opportunity';

    private const MODERN = 'Every call answered.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
    }

    public function test_visitors_see_the_classic_design_until_an_administrator_switches(): void
    {
        $this->get('/')->assertOk()->assertSee(self::CLASSIC)->assertDontSee(self::MODERN);

        app(SiteDesigns::class)->set('modern');

        $this->get('/')->assertOk()->assertSee(self::MODERN)->assertDontSee(self::CLASSIC)
            ->assertSee('index, follow', false)->assertDontSee("You're previewing", false);
    }

    public function test_the_other_public_pages_follow_the_live_design(): void
    {
        foreach (['/call-answering', '/home-property-services', '/privacy-policy', '/faq', '/business-associate-agreement'] as $path) {
            $this->get($path)->assertOk()->assertSee('legal-pages.css', false)->assertDontSee('<body class="hm">', false);
        }

        app(SiteDesigns::class)->set('modern');

        foreach (['/call-answering', '/home-property-services', '/privacy-policy', '/faq', '/business-associate-agreement'] as $path) {
            $this->get($path)->assertOk()->assertSee('<body class="hm">', false)->assertDontSee('legal-pages.css', false)
                ->assertDontSee('bootstrap.min.css', false)->assertSee('legal-pages-wrapper', false)
                ->assertSee(route('home').'#pricing', false)->assertSee('index, follow', false);
        }
    }

    public function test_the_modern_design_lists_the_website_plans_and_keeps_the_contact_form_consent(): void
    {
        Plan::create(['slug' => 'answer-pro', 'name' => 'Answer Pro', 'price_cents' => 19900, 'interval' => 'month', 'trial_days' => 14, 'is_public' => true, 'is_active' => true]);
        app(SiteDesigns::class)->set('modern');

        $this->get('/')->assertOk()->assertSee('Answer Pro')->assertSee('$199')
            ->assertSee('action="'.route('contact.store').'"', false)
            ->assertSee('name="privacy"', false)->assertSee('name="sms_consent"', false);
    }

    public function test_a_preview_link_shows_a_design_on_every_page_until_the_preview_ends(): void
    {
        $this->get(app(SiteDesigns::class)->previewUrl('modern'))->assertRedirect(route('home'));

        $this->get('/')->assertOk()->assertSee(self::MODERN)->assertSee("You're previewing the Modern design", false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('noindex, nofollow', false);
        $this->get('/privacy-policy')->assertOk()->assertSee('<body class="hm">', false)->assertSee('noindex, nofollow', false);
        $this->assertSame('classic', app(SiteDesigns::class)->current(), 'Previewing changes nothing for visitors.');

        $this->post(route('home.preview.end'))->assertRedirect(route('home'));
        $this->get('/')->assertSee(self::CLASSIC)->assertDontSee("You're previewing", false);
        $this->get('/privacy-policy')->assertDontSee('<body class="hm">', false);
    }

    public function test_previewing_classic_while_modern_is_live_and_bad_links(): void
    {
        app(SiteDesigns::class)->set('modern');
        $this->get(app(SiteDesigns::class)->previewUrl('classic'))->assertRedirect(route('home'));
        $this->get('/')->assertSee(self::CLASSIC)->assertSee("You're previewing the Classic design", false);
        $this->get('/faq')->assertSee('legal-pages.css', false)->assertSee('noindex, nofollow', false);

        $this->get(route('home.preview', ['design' => 'modern']))->assertForbidden();
        $this->get(app(SiteDesigns::class)->previewUrl('nope'))->assertNotFound();
        $this->get(route('seo.robots'))->assertSee('Disallow: /preview/');
    }

    public function test_preview_links_expire(): void
    {
        $url = app(SiteDesigns::class)->previewUrl('modern');
        $this->travel(SiteDesigns::PREVIEW_DAYS + 1)->days();

        $this->get($url)->assertForbidden();
    }

    public function test_administrators_switch_the_design_and_it_is_audited(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(Appearance::class)->assertSet('siteDesign', 'classic')
            ->assertSee('Preview Modern')->assertSee('Website design')
            ->set('siteDesign', 'modern')->call('saveDesign')->assertHasNoErrors()->assertDispatched('toast');

        $this->assertSame('modern', app(SiteDesigns::class)->current());
        $this->assertDatabaseHas('audit_logs', ['action' => 'ui.site_design_changed']);

        Livewire::test(Appearance::class)->set('siteDesign', 'fancy')->call('saveDesign')->assertHasErrors('siteDesign');
    }

    public function test_only_administrators_with_settings_access_switch_the_design(): void
    {
        $operations = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $operations->syncRoles(['operations_manager']);
        $this->actingAs($operations);
        Livewire::test(Appearance::class)->set('siteDesign', 'modern')->call('saveDesign')->assertForbidden();
        $this->assertSame('classic', app(SiteDesigns::class)->current());

        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($owner);
        $this->actingAs($owner->fresh())->get(route('admin.appearance'))->assertForbidden();
    }
}
