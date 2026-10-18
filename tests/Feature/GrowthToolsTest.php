<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\OrganizationStatus;
use App\Livewire\Client\Social\Accounts;
use App\Livewire\Client\Social\Compose;
use App\Livewire\Client\Social\Index;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\ChatWidget;
use App\Models\Organization;
use App\Models\User;
use App\Services\Growth\Openings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * G-6 growth tools (D69): the link-in-bio page and last-minute openings.
 */
class GrowthToolsTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private User $owner;

    private Organization $business;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-20 08:00', self::TZ));   // a Tuesday
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->forceFill(['name' => 'Rivera Plumbing', 'slug' => 'rivera-plumbing', 'timezone' => self::TZ, 'status' => OrganizationStatus::Active])->save();
        BusinessProfile::create(['organization_id' => $this->business->id, 'display_name' => 'Rivera Plumbing', 'phone' => '(512) 555-0100',
            'website' => 'https://rivera.test', 'description' => 'Family plumbers in Austin.']);
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $this->business->id, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        }
        $this->business = $this->business->fresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_link_page_is_public_only_when_switched_on_and_the_business_is_serving(): void
    {
        $this->get('/b/rivera-plumbing')->assertNotFound();

        $this->actingAs($this->owner);
        Livewire::test(Accounts::class)
            ->set('linkPageEnabled', true)
            ->call('addLink')->set('links.0.label', 'Our reviews')->set('links.0.url', 'not a link')
            ->call('saveLinkPage')->assertHasErrors('links.0.url')
            ->set('links.0.url', 'https://g.page/rivera/review')
            ->call('addLink')   // an empty extra row is dropped
            ->call('saveLinkPage')->assertHasNoErrors()->assertSet('links', [['label' => 'Our reviews', 'url' => 'https://g.page/rivera/review']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'social.link_page_changed', 'organization_id' => $this->business->id]);
        auth()->logout();

        $widget = ChatWidget::for($this->business);
        $widget->update(['features' => ['chat' => true, 'booking' => true, 'call' => true, 'lead' => true]]);

        $page = $this->get('/b/rivera-plumbing')->assertOk()
            ->assertSee('Rivera Plumbing')->assertSee('Family plumbers in Austin.')
            ->assertSee('href="#surehelp-book"', false)->assertSee('Book online')
            ->assertSee('href="tel:+15125550100"', false)
            ->assertSee('href="#surehelp-lead"', false)
            ->assertSee('https://rivera.test')->assertSee('Our reviews')
            ->assertSee('data-surehelp-chat="'.$widget->public_key.'"', false);
        $this->assertNotNull($page->headers->get('Content-Security-Policy'));

        // The snippet works on the link page even when the business limits it to its own site.
        $widget->update(['allowed_origins' => ['rivera.test']]);
        $this->call('GET', '/api/chat/'.$widget->public_key.'/config', [], [], [], ['HTTP_ORIGIN' => rtrim((string) config('app.url'), '/')])->assertOk();
        $this->call('GET', '/api/chat/'.$widget->public_key.'/config', [], [], [], ['HTTP_ORIGIN' => 'https://elsewhere.test'])->assertForbidden();
        // Browsers send no Origin on a same-site GET: the Referer tells where the page is.
        $this->call('GET', '/api/chat/'.$widget->public_key.'/config', [], [], [], ['HTTP_REFERER' => route('link-page', 'rivera-plumbing')])->assertOk();
        $this->call('GET', '/api/chat/'.$widget->public_key.'/config', [], [], [], ['HTTP_REFERER' => 'https://elsewhere.test/page'])->assertForbidden();
        $this->call('GET', '/api/chat/'.$widget->public_key.'/config')->assertForbidden();

        $this->business->forceFill(['status' => OrganizationStatus::Paused])->save();
        $this->get('/b/rivera-plumbing')->assertNotFound();
    }

    public function test_the_snippet_can_be_opened_from_links_on_the_page(): void
    {
        $this->get(route('chat.widget-js'))->assertOk()
            ->assertSee('window.SureHelp = {', false)
            ->assertSee('a[href^="#surehelp-"]', false);
    }

    public function test_last_minute_openings_are_spread_over_the_next_days_and_become_a_post(): void
    {
        $days = app(Openings::class)->upcoming($this->business);

        $this->assertSame(['2026-10-20', '2026-10-21', '2026-10-22'], array_map(fn ($d) => $d['date']->toDateString(), $days));
        $this->assertSame(['09:00', '11:30', '13:30', '16:00'], array_map(fn ($t) => $t->format('H:i'), $days[0]['times']));

        $this->actingAs($this->owner);
        Livewire::test(Index::class)->assertSee('Last-minute openings')->assertSee('Post about these openings');

        BusinessProfile::query()->where('organization_id', $this->business->id)->update(['link_page_enabled' => true]);
        $body = Livewire::withQueryParams(['openings' => 1])->test(Compose::class)->get('body');
        $this->assertStringContainsString('Last-minute openings this week:', $body);
        $this->assertStringContainsString('Tuesday, Oct 20: 9:00 AM, 11:30 AM, 1:30 PM, 4:00 PM', $body);
        $this->assertStringContainsString('Book yours: '.route('link-page', 'rivera-plumbing').'#surehelp-book', $body);

        // Without openings, nothing is suggested.
        BusinessHour::query()->where('organization_id', $this->business->id)->delete();
        Livewire::test(Index::class)->assertDontSee('Last-minute openings');
    }
}
