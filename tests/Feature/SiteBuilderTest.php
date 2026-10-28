<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\OrganizationStatus;
use App\Livewire\Client\SiteBuilder;
use App\Models\BusinessProfile;
use App\Models\BusinessService;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteEdit;
use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Billing\FeatureAccess;
use App\Services\Sites\SiteBuilder as Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * Hosted websites (D99): made from a template and the business's details, changed by asking in
 * plain words, previewed, published and rolled back, and kept to the business that owns them.
 */
class SiteBuilderTest extends TestCase
{
    use RefreshDatabase;

    private ScriptedAiProvider $ai;

    private User $owner;

    private Organization $business;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->ai = new ScriptedAiProvider;
        $this->app->instance(AiProvider::class, $this->ai);
        [$this->owner, $this->business] = $this->business('Rivera Plumbing');
        BusinessProfile::withoutGlobalScopes()->updateOrCreate(['organization_id' => $this->business->id], [
            'display_name' => 'Rivera Plumbing', 'phone' => '+15125550100', 'service_area' => 'Austin, TX',
            'description' => 'Family plumbers fixing leaks, drains and water heaters since we opened.',
        ]);
        BusinessService::create(['organization_id' => $this->business->id, 'name' => 'Drain cleaning', 'description' => 'Clogged drains cleared fast.', 'is_active' => true]);
        BusinessService::create(['organization_id' => $this->business->id, 'name' => 'Old service', 'is_active' => false]);
    }

    /** @return array{0: User, 1: Organization} */
    private function business(string $name): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $business = app(ProvisionUserTenancy::class)->handle($owner);
        $business->update(['name' => $name, 'status' => OrganizationStatus::Active]);

        return [$owner, $business->fresh()];
    }

    private function create(string $subdomain = 'rivera'): Site
    {
        Livewire::actingAs($this->owner)->test(SiteBuilder::class)
            ->assertSee('Create my website')
            ->set('template', 'bold')->set('subdomain', $subdomain)->call('create')->assertHasNoErrors();

        return Site::withoutGlobalScopes()->where('organization_id', $this->business->id)->sole();
    }

    /** @param  array<string, mixed>  $changes */
    private function aiAnswer(Site $site, callable $change, bool $changed = true, string $summary = 'Changed the headline.'): void
    {
        $content = $site->draft()->content;
        $change($content);
        $this->ai->reply(json_encode(['summary' => $summary, 'changed' => $changed, 'content' => $content]));
    }

    public function test_a_website_is_made_from_the_template_and_the_business_details(): void
    {
        $this->assertSame('rivera-plumbing', app(Builder::class)->suggestSubdomain($this->business));
        $site = $this->create();

        $home = $site->draft()->content['pages'][0];
        $this->assertSame('', $home['slug']);
        $this->assertSame('Rivera Plumbing', $home['sections'][0]['heading']);
        $services = collect($home['sections'])->firstWhere('type', 'services');
        $this->assertSame(['Drain cleaning'], array_column($services['items'], 'title'), 'only active services');
        $this->assertNull(collect($home['sections'])->firstWhere('type', 'reviews'), 'no invented reviews');
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.created', 'organization_id' => $this->business->id]);

        // Nothing is public until it's published.
        $this->get(route('sites.path', 'rivera'))->assertNotFound();
        $this->actingAs($this->owner)->get(route('app.site.preview'))->assertOk()->assertSee('Rivera Plumbing')->assertSee('Drain cleaning')->assertSee('Preview of version 1');

        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->call('publish')->assertDispatched('toast', type: 'success');
        auth()->logout();
        $this->get(route('sites.path', 'rivera'))->assertOk()
            ->assertSee('<h1>Rivera Plumbing</h1>', false)->assertSee('Drain cleaning')->assertDontSee('Old service')
            ->assertSee('tel:+15125550100', false)->assertSee('"@type":"LocalBusiness"', false)->assertDontSee('Preview of version');
    }

    public function test_addresses_must_be_valid_free_and_not_reserved(): void
    {
        [$otherOwner, $other] = $this->business('Other Co');
        app(Builder::class)->create($other, 'clean', 'taken-name', $otherOwner);

        foreach (['www' => 'reserved', 'taken-name' => 'taken', 'a' => 'letters', 'bad_name!' => 'letters'] as $subdomain => $why) {
            Livewire::actingAs($this->owner)->test(SiteBuilder::class)->set('subdomain', $subdomain)->call('create')->assertHasErrors('subdomain');
        }
        $this->assertSame(0, Site::withoutGlobalScopes()->where('organization_id', $this->business->id)->count());
    }

    public function test_a_change_asked_in_plain_words_becomes_a_draft_until_published(): void
    {
        $site = $this->create();
        app(Builder::class)->publish($site, $this->owner);
        $this->aiAnswer($site, function (array &$c) {
            $c['pages'][0]['sections'][0]['heading'] = '24/7 emergency plumbing <script>alert(1)</script>';
        });

        Livewire::actingAs($this->owner)->test(SiteBuilder::class)
            ->set('prompt', 'Make the headline about 24/7 emergency plumbing')->call('ask')->assertHasNoErrors();

        $edit = SiteEdit::withoutGlobalScopes()->sole();
        $this->assertSame('done', $edit->status);
        $this->assertSame('Changed the headline.', $edit->summary);
        $this->assertSame(1950, $edit->input_tokens + $edit->output_tokens);
        $draft = $site->fresh()->draft();
        $this->assertSame(2, $draft->number);
        $this->assertSame('24/7 emergency plumbing alert(1)', $draft->content['pages'][0]['sections'][0]['heading'], 'no HTML gets in');
        $this->assertStringContainsString('Business facts', $this->ai->requests[0]->instructions);
        $this->assertStringContainsString('Make the headline about 24/7 emergency plumbing', $this->ai->requests[0]->messages[0]['content']);

        // Visitors still see version 1 until it's published.
        $this->get(route('sites.path', 'rivera'))->assertSee('<h1>Rivera Plumbing</h1>', false);
        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->assertSee('Unpublished changes')->call('publish');
        $this->get(route('sites.path', 'rivera'))->assertSee('24/7 emergency plumbing');
    }

    public function test_the_ai_can_add_a_page_and_decline_off_topic_requests(): void
    {
        $site = $this->create();
        $this->aiAnswer($site, function (array &$c) {
            $c['pages'][] = ['slug' => 'Drain Cleaning!', 'title' => 'Drain cleaning', 'seo_title' => '', 'seo_description' => '',
                'sections' => [['id' => 'x1', 'type' => 'text', 'heading' => 'Drain cleaning', 'text' => 'We clear drains.', 'button' => '', 'items' => []],
                    ['id' => 'x2', 'type' => 'carousel', 'heading' => 'Not a type', 'text' => '', 'button' => '', 'items' => []]]];
        }, summary: 'Added a drain cleaning page.');
        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->set('prompt', 'Add a page about drain cleaning')->call('ask');

        $pages = $site->fresh()->draft()->content['pages'];
        $this->assertSame(['', 'drain-cleaning'], array_column($pages, 'slug'));
        $this->assertCount(1, $pages[1]['sections'], 'unknown section types are dropped');
        $this->actingAs($this->owner)->get(route('app.site.preview', 'drain-cleaning'))->assertOk()->assertSee('We clear drains.');

        $this->aiAnswer($site, fn (array &$c) => null, changed: false, summary: 'That isn\'t about your website, so nothing changed.');
        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->set('prompt', 'Write my tax return')->call('ask');
        $this->assertSame('nothing', SiteEdit::withoutGlobalScopes()->latest('id')->first()->status);
        $this->assertSame(2, $site->fresh()->draft()->number, 'no new version');
    }

    public function test_the_monthly_allowance_stops_new_changes(): void
    {
        config(['sites.monthly_tokens' => 10000]);
        $site = $this->create();
        SiteEdit::create(['site_id' => $site->id, 'organization_id' => $this->business->id, 'prompt' => 'earlier', 'status' => 'done', 'input_tokens' => 9000, 'output_tokens' => 500]);

        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->set('prompt', 'Change the colour to green')->call('ask')
            ->assertHasErrors('prompt')->assertSee('allowance for your website is used up');
        $this->assertSame([], $this->ai->requests);
    }

    public function test_an_older_version_can_be_restored_and_unpublished_changes_dropped(): void
    {
        $site = $this->create();
        app(Builder::class)->publish($site, $this->owner);
        $this->aiAnswer($site, function (array &$c) {
            $c['theme']['accent'] = '#166534';
        });
        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->set('prompt', 'Use dark green')->call('ask');
        $this->assertSame('#166534', $site->fresh()->draft()->content['theme']['accent']);

        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->call('discard');
        $draft = $site->fresh()->draft();
        $this->assertSame(3, $draft->number);
        $this->assertSame($site->published->content, $draft->content);

        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->call('restore', 2);
        $this->assertSame('#166534', $site->fresh()->draft()->content['theme']['accent']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'site.restored']);
    }

    public function test_sites_stay_with_their_own_business(): void
    {
        $site = $this->create();
        app(Builder::class)->publish($site, $this->owner);
        [$otherOwner, $other] = $this->business('Other Co');

        // Another business has no site of its own: its preview and actions find nothing of ours.
        $this->actingAs($otherOwner)->get(route('app.site.preview'))->assertNotFound();
        $this->actingAs($otherOwner)->get(route('app.site.preview', ['version' => 1]))->assertNotFound();
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($otherOwner)->test(SiteBuilder::class)->call('restore', 1);
    }

    public function test_a_paused_business_or_switched_off_feature_takes_the_site_offline(): void
    {
        $site = $this->create();
        app(Builder::class)->publish($site, $this->owner);
        $this->get(route('sites.path', 'rivera'))->assertOk();

        $this->business->forceFill(['status' => OrganizationStatus::Paused])->save();
        $this->get(route('sites.path', 'rivera'))->assertNotFound();

        $this->business->forceFill(['status' => OrganizationStatus::Active])->save();
        DB::table('organization_features')->insert(['organization_id' => $this->business->id, 'feature' => 'website_builder', 'enabled' => false]);
        app(FeatureAccess::class)->forget($this->business);
        $this->get(route('sites.path', 'rivera'))->assertNotFound();
        $this->actingAs($this->owner)->get(route('app.site'))->assertRedirect();
    }

    public function test_team_members_without_access_cannot_open_the_builder(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($agent)->get(route('app.site'))->assertForbidden();
        $this->get(route('sites.path', 'nobody-here'))->assertNotFound();
    }
}
