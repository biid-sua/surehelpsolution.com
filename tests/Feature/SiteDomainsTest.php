<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\OrganizationStatus;
use App\Livewire\Client\SiteBuilder;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\Domains\CloudflareDomainProvider;
use App\Services\Sites\Domains\DomainProvider;
use App\Services\Sites\Domains\FakeDomainProvider;
use App\Services\Sites\SiteBuilder as Builder;
use App\Services\Sites\SiteDomains;
use App\Services\Websites\DnsResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\FakeDnsResolver;
use Tests\TestCase;

/**
 * Businesses' own domains for their hosted websites (D101): connect, the DNS record to add, checks
 * for DNS and the certificate, serving on the domain, redirects from the free address, and removal.
 */
class SiteDomainsTest extends TestCase
{
    use RefreshDatabase;

    /** The free address, on the app's own host (route() would reuse the last request's host). */
    private const FREE = 'http://localhost/site/bright';

    private FakeDnsResolver $dns;

    private FakeDomainProvider $provider;

    private User $owner;

    private Organization $business;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['sites.custom_domains.cname_target' => 'sites.surehelp.site', 'sites.custom_domains.ip' => null]);
        $this->dns = new FakeDnsResolver(['sites.surehelp.site' => ['203.0.113.10']]);
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->provider = new FakeDomainProvider;
        $this->app->instance(DomainProvider::class, $this->provider);
        [$this->owner, $this->business] = $this->business('Bright Plumbing');
        $this->site = app(Builder::class)->create($this->business, 'bold', 'bright', $this->owner);
        app(Builder::class)->publish($this->site, $this->owner);
    }

    /** @return array{0: User, 1: Organization} */
    private function business(string $name): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $business = app(ProvisionUserTenancy::class)->handle($owner);
        $business->update(['name' => $name, 'status' => OrganizationStatus::Active]);

        return [$owner, $business->fresh()];
    }

    private function connect(string $domain): Site
    {
        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->set('ownDomain', $domain)->call('connectDomain')->assertHasNoErrors();

        return $this->site->fresh();
    }

    public function test_an_owner_connects_a_domain_and_visitors_see_the_site_there(): void
    {
        $site = $this->connect('https://WWW.BrightPlumbing.com/');
        $this->assertSame('www.brightplumbing.com', $site->custom_domain);
        $this->assertSame('pending', $site->domain_status);
        $this->assertArrayHasKey('www.brightplumbing.com', $this->provider->domains);
        $this->assertSame(['type' => 'CNAME', 'name' => 'www', 'value' => 'sites.surehelp.site'], app(SiteDomains::class)->record($site));
        $this->assertStringContainsString('doesn\'t point to us yet', (string) $site->domain_error);
        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->assertSee('sites.surehelp.site')->assertSee('Waiting');

        // Not live yet: the domain shows a plain note, and the free address still works.
        $this->get('http://www.brightplumbing.com/')->assertNotFound()->assertSee('being set up');
        $this->get(self::FREE)->assertOk();

        $this->dns->cnames['www.brightplumbing.com'] = ['sites.surehelp.site'];
        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->call('checkDomain')->assertDispatched('toast', type: 'success');
        $site = $site->fresh();
        $this->assertSame('active', $site->domain_status);
        $this->assertSame('https://www.brightplumbing.com', $site->url());
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.domain_active', 'organization_id' => $this->business->id]);

        $this->get('http://www.brightplumbing.com/')->assertOk()->assertSee('<h1>Bright Plumbing</h1>', false)
            ->assertHeader('X-Robots-Tag', 'all')->assertSee('<link rel="canonical" href="https://www.brightplumbing.com">', false);
        $this->get('http://www.brightplumbing.com/no-such-page')->assertNotFound();
        $this->get(self::FREE)->assertRedirect('https://www.brightplumbing.com');
    }

    public function test_domains_must_be_real_free_and_not_ours(): void
    {
        [$otherOwner, $other] = $this->business('Other Co');
        $otherSite = app(Builder::class)->create($other, 'clean', 'other-co', $otherOwner);
        app(SiteDomains::class)->connect($otherSite, 'www.taken.com', $otherOwner);

        foreach (['not a domain', 'www.taken.com', 'localhost', 'brightplumbing.com'] as $input) {
            Livewire::actingAs($this->owner)->test(SiteBuilder::class)->set('ownDomain', $input)->call('connectDomain')->assertHasErrors('ownDomain');
        }
        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->set('ownDomain', 'brightplumbing.com')->call('connectDomain')->assertSee('Use the www version');
        $this->assertNull($this->site->fresh()->custom_domain);
    }

    public function test_a_bare_domain_works_with_an_a_record_when_we_have_an_address(): void
    {
        config(['sites.custom_domains.ip' => '203.0.113.10']);
        $site = $this->connect('brightplumbing.com');
        $this->assertSame(['type' => 'A', 'name' => '@', 'value' => '203.0.113.10'], app(SiteDomains::class)->record($site));

        $this->dns->a['brightplumbing.com'] = ['203.0.113.10'];
        $this->assertSame('active', app(SiteDomains::class)->check($site)->domain_status);
    }

    public function test_the_domain_waits_for_its_certificate_and_says_why(): void
    {
        $this->dns->cnames['www.brightplumbing.com'] = ['sites.surehelp.site'];
        $this->provider->ready = false;
        $site = $this->connect('www.brightplumbing.com');
        $this->assertSame('pending', $site->domain_status);
        $this->assertStringContainsString('security certificate', (string) $site->domain_error);

        $this->provider->error = 'The certificate couldn\'t be issued yet: CAA record blocks issuance.';
        $this->assertStringContainsString('CAA', (string) app(SiteDomains::class)->check($site)->domain_error);

        // The provider being down at connect time is a clear message, not a broken page.
        $this->provider->fail = true;
        app(SiteDomains::class)->remove($site, $this->owner);
        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->set('ownDomain', 'www.brightplumbing.com')->call('connectDomain')
            ->assertHasErrors('ownDomain')->assertSee('try again in a few minutes');
    }

    public function test_the_regular_check_activates_domains_and_rechecks_working_ones(): void
    {
        $site = $this->connect('www.brightplumbing.com');
        $this->dns->cnames['www.brightplumbing.com'] = ['sites.surehelp.site'];
        $this->artisan('sites:check-domains')->assertSuccessful();
        $this->assertSame('active', $site->fresh()->domain_status);

        // The owner points the domain elsewhere: a day later it's back to waiting, and the free address serves again.
        $this->dns->cnames = [];
        $this->travel(25)->hours();
        $this->artisan('sites:check-domains')->assertSuccessful();
        $this->assertSame('pending', $site->fresh()->domain_status);
        $this->get(self::FREE)->assertOk();
    }

    public function test_certificates_are_only_allowed_for_connected_domains(): void
    {
        $this->connect('www.brightplumbing.com');

        $this->get(route('sites.domain-allowed', ['domain' => 'www.brightplumbing.com']))->assertOk();
        $this->get(route('sites.domain-allowed', ['domain' => 'www.someone-else.com']))->assertNotFound();
        $this->get(route('sites.domain-allowed'))->assertNotFound();
    }

    public function test_removing_the_domain_goes_back_to_the_free_address(): void
    {
        $this->dns->cnames['www.brightplumbing.com'] = ['sites.surehelp.site'];
        $this->connect('www.brightplumbing.com');

        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->call('removeDomain')->assertDispatched('toast', type: 'success');
        $this->assertNull($this->site->fresh()->custom_domain);
        $this->assertSame([], $this->provider->domains);
        $this->get('http://www.brightplumbing.com/')->assertDontSee('Bright Plumbing');
        $this->get(self::FREE)->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.domain_removed']);
    }

    public function test_a_paused_business_is_offline_on_its_domain_and_others_cannot_touch_it(): void
    {
        $this->dns->cnames['www.brightplumbing.com'] = ['sites.surehelp.site'];
        $this->connect('www.brightplumbing.com');
        $this->business->forceFill(['status' => OrganizationStatus::Paused])->save();
        $this->get('http://www.brightplumbing.com/')->assertNotFound();

        [$otherOwner] = $this->business('Other Co');
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($otherOwner)->test(SiteBuilder::class)->call('removeDomain');
    }

    public function test_the_cloudflare_driver_adds_checks_and_removes_custom_hostnames(): void
    {
        config(['sites.custom_domains.cloudflare' => ['token' => 'cf-token', 'zone' => 'zone123']]);
        $base = 'https://api.cloudflare.com/client/v4/zones/zone123/custom_hostnames';
        Http::fake([
            $base.'?hostname=www.brightplumbing.com' => Http::response(['success' => true, 'result' => []]),
            $base.'/ch_1' => Http::sequence()
                ->push(['success' => true, 'result' => ['id' => 'ch_1', 'status' => 'pending', 'ssl' => ['status' => 'pending_validation']]])
                ->push(['success' => true, 'result' => ['id' => 'ch_1', 'status' => 'active', 'ssl' => ['status' => 'active']]])
                ->push(['success' => true]),
            $base => Http::response(['success' => true, 'result' => ['id' => 'ch_1']]),
        ]);
        $cloudflare = new CloudflareDomainProvider;

        $this->assertSame('ch_1', $cloudflare->add('www.brightplumbing.com'));
        $this->assertFalse($cloudflare->status('www.brightplumbing.com', 'ch_1')['ready']);
        $this->assertTrue($cloudflare->status('www.brightplumbing.com', 'ch_1')['ready']);
        $cloudflare->remove('www.brightplumbing.com', 'ch_1');

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === $base
            && $request['hostname'] === 'www.brightplumbing.com' && $request['ssl']['method'] === 'http' && $request->hasHeader('Authorization', 'Bearer cf-token'));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === $base.'/ch_1');
    }
}
