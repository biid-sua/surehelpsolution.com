<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Jobs\CheckWebsite;
use App\Livewire\Client\Website as WebsitePage;
use App\Models\Appointment;
use App\Models\BusinessHour;
use App\Models\BusinessLocation;
use App\Models\BusinessProfile;
use App\Models\BusinessService;
use App\Models\ChatWidget;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Models\Website;
use App\Services\Websites\DnsResolver;
use App\Services\Websites\SafeFetcher;
use App\Services\Websites\WebsiteHealthCheck;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\FakeDnsResolver;
use Tests\TestCase;

/**
 * G-3 Connect a website (spec §41B, D44): ownership, the protected fetcher, the health and SEO
 * check, and booking, click-to-call and the contact form in the snippet.
 */
class WebsiteConnectTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 08:00', self::TZ));   // a Monday
        $this->dns = new FakeDnsResolver(['rivera.test' => ['93.184.216.34'], 'www.rivera.test' => ['93.184.216.34']]);
        $this->app->instance(DnsResolver::class, $this->dns);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Organization} */
    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->update(['timezone' => self::TZ, 'name' => 'Rivera Plumbing']);
        BusinessProfile::create(['organization_id' => $org->id, 'display_name' => 'Rivera Plumbing', 'phone' => '(512) 555-0100']);
        BusinessLocation::create(['organization_id' => $org->id, 'name' => 'Main', 'city' => 'Austin', 'postal_code' => '78701', 'is_primary' => true]);
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $org->id, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        }

        return [$owner, $org->fresh()];
    }

    private function site(Organization $org, bool $verified = true): Website
    {
        return Website::create(['organization_id' => $org->id, 'url' => 'https://rivera.test/', 'host' => 'rivera.test', 'verified_at' => $verified ? now() : null]);
    }

    private function widget(string $method, ChatWidget $widget, string $uri, array $body = [], string $origin = 'https://rivera.test'): TestResponse
    {
        return $this->call($method, '/api/chat/'.$widget->public_key.$uri, [], [], [], ['HTTP_ORIGIN' => $origin, 'CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => '203.0.113.9'], $body ? json_encode($body) : null);
    }

    public function test_owners_add_a_website_and_verify_it_by_meta_tag_or_dns(): void
    {
        Bus::fake([CheckWebsite::class]);
        [$owner, $org] = $this->business();
        $this->actingAs($owner)->get(route('app.website'))->assertOk()->assertSee('No website yet')->assertSee('data-surehelp-chat', false);

        $page = Livewire::test(WebsitePage::class)
            ->set('newUrl', 'localhost')->call('add')->assertHasErrors('newUrl')
            ->set('newUrl', 'http://10.0.0.5')->call('add')->assertHasErrors('newUrl')
            ->set('newUrl', 'https://Rivera.test/services?x=1')->call('add')->assertHasNoErrors();
        $site = Website::sole();
        $this->assertSame(['https://rivera.test/', 'rivera.test'], [$site->url, $site->host]);
        $page->set('newUrl', 'rivera.test')->call('add')->assertHasErrors('newUrl');

        // Not there yet.
        $home = '<html><head><title>Home</title></head></html>';
        Http::fake(['rivera.test/*' => function () use (&$home) {
            return Http::response($home);
        }, '*' => Http::response('', 500)]);
        $page->call('verify', $site->ulid);
        $this->assertNull($site->fresh()->verified_at);

        // The meta tag on the home page.
        $home = '<html><head>'.$site->metaTag().'</head></html>';
        $page->call('verify', $site->ulid);
        $this->assertSame('meta', $site->fresh()->verified_via);
        Bus::assertDispatched(CheckWebsite::class);

        // Or a TXT record on the bare domain for a www site.
        $www = Website::create(['organization_id' => $org->id, 'url' => 'https://www.rivera.test/', 'host' => 'www.rivera.test']);
        $this->dns->txt['rivera.test'] = ['v=spf1 -all', '"'.$www->dnsRecord().'"'];
        $page->call('verify', $www->ulid);
        $this->assertSame('dns', $www->fresh()->verified_via);

        // Staff can't open it; managers can; other businesses' sites are out of reach.
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $org->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->actingAs($staff)->get(route('app.website'))->assertForbidden();
        $manager = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $org->members()->attach($manager->id, ['role' => 'manager', 'status' => 'active']);
        $this->actingAs($manager);
        Livewire::test(WebsitePage::class)->assertSee('rivera.test')->set('newUrl', 'other.test')->call('add')->assertHasNoErrors();   // managers look after integrations
        foreach (['three.test', 'four.test', 'five.test'] as $host) {
            Livewire::test(WebsitePage::class)->set('newUrl', $host)->call('add');
        }
        Livewire::test(WebsitePage::class)->set('newUrl', 'six.test')->call('add')->assertHasErrors('newUrl');   // five at most

        // Removing one site.
        $last = Website::where('host', 'four.test')->sole();
        Livewire::test(WebsitePage::class)->call('remove', $last->id);
        $this->assertNull(Website::find($last->id));

        [$other] = $this->business();
        $this->actingAs($other);
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(WebsitePage::class)->call('verify', $site->ulid);
    }

    public function test_the_fetcher_never_reaches_private_networks(): void
    {
        $fetcher = app(SafeFetcher::class);
        $this->dns->a['internal.test'] = ['10.0.0.5'];
        $this->dns->a['metadata.test'] = ['169.254.169.254'];
        $this->dns->a['mixed.test'] = ['93.184.216.34', '127.0.0.1'];

        foreach (['file:///etc/passwd', 'ftp://rivera.test/', 'http://internal.test/', 'http://metadata.test/latest', 'https://mixed.test/', 'http://127.0.0.1/', 'http://[::1]/',
            'https://user:pass@rivera.test/', 'https://rivera.test:8443/', 'https://unknown.test/'] as $url) {
            $this->assertIsString($fetcher->target($url), $url);
        }
        $this->assertSame(['rivera.test', 443, '93.184.216.34'], $fetcher->target('https://rivera.test/'));

        // A redirect to a private address is refused too.
        Http::fake(['rivera.test/*' => Http::response('', 302, ['Location' => 'http://internal.test/admin']), 'internal.test/*' => Http::response('secret')]);
        $result = $fetcher->get('https://rivera.test/');
        $this->assertNull($result->status);
        $this->assertStringContainsString('private network', (string) $result->error);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'internal.test'));
    }

    public function test_the_health_check_explains_what_to_fix(): void
    {
        [, $org] = $this->business();
        $site = $this->site($org);
        $key = ChatWidget::for($org)->public_key;
        Http::fake([
            'rivera.test/robots.txt' => Http::response("User-agent: *\nDisallow: /\n"),
            'rivera.test/sitemap.xml' => Http::response('', 404),
            'rivera.test/about' => Http::response('<html><head><title>About</title><meta name="viewport" content="width=device-width"></head><body><h1>About</h1><h1>Us</h1><img src="a.jpg"></body></html>'),
            'rivera.test/old-page' => Http::response('', 404),
            'rivera.test/' => Http::response('<html><head><title>Rivera Plumbing — Austin plumbers</title>'
                .'<script type="application/ld+json">{"@context":"https://schema.org","@type":"Plumber","name":"Rivera Plumbing"}</script></head>'
                .'<body><h1>Rivera Plumbing</h1><p>Serving Austin since 1999.</p><a href="/about">About</a> <a href="/old-page">Old</a>'
                .'<a href="https://elsewhere.test/">Partner</a><script src="https://app.test/api/chat/widget.js" data-surehelp-chat="'.$key.'"></script></body></html>'),
        ]);

        app(WebsiteHealthCheck::class)->run($site->load('organization'));
        $site->refresh();
        $byKey = collect($site->health['findings'])->keyBy('key');

        $this->assertSame('problem', $byKey['mobile']['status'], 'no viewport on the home page');
        $this->assertSame('problem', $byKey['robots_blocked']['status']);
        $this->assertSame('problem', $byKey['broken_links']['status']);
        $this->assertSame(['https://rivera.test/old-page'], $byKey['broken_links']['pages']);
        $this->assertSame('problem', $byKey['nap_phone']['status'], '(512) 555-0100 is missing');
        $this->assertSame('warning', $byKey['descriptions']['status']);
        $this->assertSame('warning', $byKey['h1_many']['status']);
        $this->assertSame('warning', $byKey['alt_text']['status']);
        $this->assertSame('warning', $byKey['sitemap']['status']);
        $this->assertSame('good', $byKey['structured_data']['status']);
        $this->assertSame('good', $byKey['snippet']['status']);
        $this->assertSame('good', $byKey['https']['status']);
        $this->assertArrayNotHasKey('nap_name', $byKey->all());
        $this->assertArrayNotHasKey('nap_address', $byKey->all(), 'Austin is on the page');
        $this->assertSame(['https://rivera.test/', 'https://rivera.test/about'], $site->health['checked_pages']);
        $this->assertLessThan(50, $site->health_score);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'elsewhere.test'));

        // An unreachable site keeps the last results and says why.
        $this->dns->a['down.test'] = ['93.184.216.34'];
        Http::fake(['down.test/*' => Http::response('', 503)]);
        $site->forceFill(['url' => 'https://down.test/', 'host' => 'down.test'])->save();
        app(WebsiteHealthCheck::class)->run($site);
        $this->assertNotNull($site->fresh()->health);
        $this->assertStringContainsString('503', (string) $site->fresh()->last_error);
    }

    public function test_verified_sites_are_checked_again_every_month(): void
    {
        Bus::fake([CheckWebsite::class]);
        [, $org] = $this->business();
        $due = $this->site($org);
        $due->forceFill(['last_checked_at' => now()->subDays(31)])->save();
        Website::create(['organization_id' => $org->id, 'url' => 'https://www.rivera.test/', 'host' => 'www.rivera.test', 'verified_at' => now(), 'last_checked_at' => now()->subDays(3)]);
        Website::create(['organization_id' => $org->id, 'url' => 'https://new.test/', 'host' => 'new.test']);

        $this->artisan('websites:check')->expectsOutputToContain('Queued 1 website check.');
        Bus::assertDispatched(CheckWebsite::class, fn (CheckWebsite $job) => $job->websiteId === $due->id);
    }

    public function test_visitors_book_online_and_it_lands_in_the_crm(): void
    {
        [$owner, $org] = $this->business();
        $service = BusinessService::create(['organization_id' => $org->id, 'name' => 'Leak repair', 'duration_minutes' => 60, 'price_type' => 'fixed',
            'price_cents' => 9000, 'currency' => 'USD', 'is_active' => true, 'is_bookable' => true]);
        $widget = ChatWidget::for($org);
        $widget->update(['allowed_origins' => ['rivera.test']]);

        $this->widget('GET', $widget, '/booking/services')->assertNotFound();   // off until switched on
        $this->actingAs($owner);
        Livewire::test(WebsitePage::class)->set('features.booking', true)->set('features.call', true)->set('features.lead', true)->call('saveFeatures');
        $widget->refresh();

        $this->widget('GET', $widget, '/config')->assertOk()
            ->assertJsonPath('features', ['chat' => true, 'booking' => true, 'call' => true, 'lead' => true])
            ->assertJsonPath('phone.tel', '+15125550100')->assertJsonPath('booking_confirm', true);
        $this->widget('GET', $widget, '/booking/services')->assertOk()->assertJsonPath('services.0.name', 'Leak repair');
        $this->widget('GET', $widget, '/booking/services', origin: 'https://evil.test')->assertForbidden();

        $times = $this->widget('GET', $widget, '/booking/slots?date=2026-10-06&service='.$service->id)->assertOk()->json('times');
        $this->assertSame(['value' => '2026-10-06 09:00', 'label' => '9:00 AM'], $times[0]);
        $this->assertSame([], $this->widget('GET', $widget, '/booking/slots?date=2026-10-04')->json('times'), 'the past');

        $this->widget('POST', $widget, '/booking', ['starts_at' => '2026-10-06 09:00', 'service' => $service->id, 'phone' => '512 555 0147'])
            ->assertStatus(422)->assertJsonPath('message', 'Please tell us your name.');
        $this->widget('POST', $widget, '/booking', ['starts_at' => '2026-10-06 09:00', 'name' => 'Ana'])->assertStatus(422);
        $this->widget('POST', $widget, '/booking', ['starts_at' => '2026-10-06 09:00', 'service' => $service->id, 'name' => 'Ana Lopez', 'phone' => '512 555 0147', 'address' => '12 Oak St'])
            ->assertCreated()->assertJsonPath('status', 'pending');

        $appointment = Appointment::sole();
        $this->assertSame(['website', 'pending', $service->id, '12 Oak St'], [$appointment->source, $appointment->status->value, $appointment->service_id, $appointment->address]);
        $this->assertSame('website', $appointment->customer->source);
        $this->assertSame('+15125550147', $appointment->customer->phone_e164);

        // The same time again is taken; a bot filling the hidden field is ignored.
        $this->widget('POST', $widget, '/booking', ['starts_at' => '2026-10-06 09:00', 'service' => $service->id, 'name' => 'Ben', 'phone' => '5125550148'])->assertStatus(409);
        $this->widget('POST', $widget, '/booking', ['starts_at' => '2026-10-06 13:00', 'name' => 'Bot', 'phone' => '5125550149', 'website' => 'http://spam'])->assertCreated();
        $this->assertSame(1, Appointment::count());
    }

    public function test_the_contact_form_creates_a_follow_up_and_is_rate_limited(): void
    {
        [, $org] = $this->business();
        $widget = ChatWidget::for($org);
        $this->widget('POST', $widget, '/lead', ['name' => 'Ana', 'phone' => '5125550147', 'message' => 'Quote?'])->assertNotFound();
        $widget->update(['features' => ['chat' => false, 'lead' => true]]);

        $this->widget('POST', $widget, '/messages', ['body' => 'hi'])->assertNotFound();   // chat switched off
        $this->widget('POST', $widget, '/lead', ['name' => 'Ana', 'email' => 'ana@example.test'])->assertStatus(422);
        $this->widget('POST', $widget, '/lead', ['name' => 'Ana <b>Lopez</b>', 'phone' => '(512) 555-0147', 'message' => 'Can you quote a new water heater?', 'page' => 'https://rivera.test/heaters'])
            ->assertCreated();

        $task = Task::withoutGlobalScopes()->sole();
        $this->assertSame(['callback', 'website', 'high'], [$task->type->value, $task->source, $task->priority->value]);
        $this->assertSame('Website enquiry from Ana Lopez', $task->title);
        $this->assertStringContainsString('Sent from: https://rivera.test/heaters', $task->description);
        $this->assertSame('website', Customer::withoutGlobalScopes()->sole()->source);

        foreach (range(1, 4) as $i) {
            $this->widget('POST', $widget, '/lead', ['name' => 'Ana', 'email' => "a{$i}@example.test", 'message' => 'Again'])->assertCreated();
        }
        $this->widget('POST', $widget, '/lead', ['name' => 'Ana', 'email' => 'a6@example.test', 'message' => 'Again'])->assertStatus(429);
    }
}
