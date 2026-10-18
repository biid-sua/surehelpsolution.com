<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\InboxChannel;
use App\Enums\SocialNetwork;
use App\Livewire\Client\Results;
use App\Models\Appointment;
use App\Models\ChatWidget;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Metrics\ResultsReport;
use App\Services\Metrics\SourceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * G-5 — where customers and bookings came from (D68): sources counted from SureHelp's own records, pages
 * viewed with the website snippet, and social posts and messages, on Results, the PDF and the CSV.
 */
class SourceReportTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private User $owner;

    private Organization $business;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-20 12:00', self::TZ));
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->forceFill(['name' => 'Rivera Plumbing', 'timezone' => self::TZ, 'created_at' => '2026-08-01'])->save();
        $this->business = $this->business->fresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(string $source, string $at = '2026-10-05 10:00'): Customer
    {
        $customer = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'C'.random_int(1, 99999), 'source' => $source]);
        $customer->forceFill(['created_at' => CarbonImmutable::parse($at, self::TZ)])->save();

        return $customer;
    }

    private function conversation(Customer $customer, InboxChannel $channel, string $at = '2026-10-05 09:00'): void
    {
        $conversation = Conversation::create(['organization_id' => $this->business->id, 'channel' => $channel, 'channel_key' => 'k', 'external_thread_id' => 't'.$customer->id.$channel->value, 'customer_id' => $customer->id]);
        $conversation->forceFill(['created_at' => CarbonImmutable::parse($at, self::TZ)])->save();
    }

    private function appointment(string $source, string $at = '2026-10-08 10:00'): void
    {
        $start = CarbonImmutable::parse('2026-10-25 10:00', self::TZ);
        $appointment = Appointment::create(['organization_id' => $this->business->id, 'title' => 'Job', 'timezone' => self::TZ, 'status' => 'confirmed',
            'starts_at' => $start, 'ends_at' => $start->addHour(), 'blocked_until' => $start->addHour(), 'source' => $source]);
        $appointment->forceFill(['created_at' => CarbonImmutable::parse($at, self::TZ)])->save();
    }

    private function october(): array
    {
        $period = app(ResultsReport::class)->month($this->business, '2026-10');

        return app(SourceReport::class)->compute($this->business, $period['start'], $period['end']);
    }

    public function test_new_customers_are_counted_by_how_they_first_got_in_touch(): void
    {
        $this->customer('call');
        $this->customer('call');
        $this->customer('website');
        $this->customer('chatbot');
        $instagram = $this->customer('message');
        $this->conversation($instagram, InboxChannel::Instagram, '2026-10-04 09:00');
        $this->conversation($instagram, InboxChannel::WebChat, '2026-10-06 09:00');   // later: not the first
        $this->conversation($this->customer('message'), InboxChannel::Sms);
        $this->customer('manual');
        $this->customer('import');
        $this->customer('call', '2026-09-30 10:00');   // September
        $this->customer('call', '2026-09-12 10:00');

        $s = $this->october();
        $this->assertSame(['phone' => 2, 'website' => 1, 'web_chat' => 1, 'facebook' => 0, 'instagram' => 1, 'sms' => 1], $s['leads']);
        $this->assertSame([6, 2, 2], [$s['leads_total'], $s['added_by_team'], $s['website_leads']]);
        $this->assertSame(200, $s['change']['leads_total']);   // 6 vs 2 in September
    }

    public function test_bookings_are_counted_by_who_made_them_without_synced_calendar_events(): void
    {
        foreach (['agent', 'agent', 'website', 'chatbot', 'ai', 'portal', 'api', 'calendar'] as $source) {
            $this->appointment($source);
        }
        $this->appointment('agent', '2026-11-01 10:00');   // November

        $s = $this->october();
        $this->assertSame(['agent' => 2, 'website' => 1, 'ai' => 2, 'business' => 2], $s['bookings']);
        $this->assertSame(7, $s['bookings_total']);
    }

    public function test_pages_viewed_are_counted_from_the_snippet_without_keeping_visitor_details(): void
    {
        $this->assertNull($this->october()['website_views']);

        $widget = ChatWidget::for($this->business);
        foreach (range(1, 3) as $i) {
            $this->call('GET', '/api/chat/'.$widget->public_key.'/config', [], [], [], ['HTTP_ORIGIN' => 'https://rivera.test', 'REMOTE_ADDR' => '203.0.113.'.$i])->assertOk();
        }
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-21 23:30', self::TZ));
        $this->call('GET', '/api/chat/'.$widget->public_key.'/config', [], [], [], ['HTTP_ORIGIN' => 'https://rivera.test'])->assertOk();

        $this->assertSame(4, $this->october()['website_views']);
        $this->assertSame([['day' => '2026-10-20', 'views' => 3], ['day' => '2026-10-21', 'views' => 1]],
            DB::table('website_daily_views')->orderBy('day')->get(['day', 'views'])->map(fn ($r) => ['day' => substr((string) $r->day, 0, 10), 'views' => (int) $r->views])->all());
        $this->assertSame(['id', 'organization_id', 'day', 'views'], Schema::getColumnListing('website_daily_views'));
    }

    public function test_social_posts_and_messages_and_the_results_page_pdf_and_csv(): void
    {
        $account = SocialAccount::create(['organization_id' => $this->business->id, 'network' => SocialNetwork::Facebook, 'external_id' => '1', 'name' => 'Rivera', 'access_token' => 't', 'is_enabled' => true]);
        $post = SocialPost::create(['organization_id' => $this->business->id, 'body' => 'Hi', 'status' => 'published']);
        SocialPostTarget::create(['social_post_id' => $post->id, 'social_account_id' => $account->id, 'status' => SocialPostTarget::PUBLISHED, 'published_at' => CarbonImmutable::parse('2026-10-03 10:00', self::TZ)]);
        $this->conversation($this->customer('message'), InboxChannel::Facebook);
        $this->customer('call');
        $this->appointment('website');

        $s = $this->october();
        $this->assertSame([1, 1, 1], [$s['social_posts'], $s['social_messages'], $s['leads']['facebook']]);

        $this->actingAs($this->owner);
        Livewire::test(Results::class)->set('month', '2026-10')
            ->assertSee('Where customers came from')->assertSee('Facebook Messenger')->assertSee('Online booking on your website')
            ->assertSee('add the SureHelp snippet to your site');

        $csv = $this->get(route('app.results.csv', ['month' => '2026-10']))->assertOk()->streamedContent();
        $this->assertStringContainsString('"New customers by source","Phone calls",1', $csv);
        $this->assertStringContainsString('"Bookings by source","Online booking on your website",1', $csv);
        $this->assertStringContainsString('"Social media","Posts published",1', $csv);

        $this->get(route('app.results.pdf', ['month' => '2026-10']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
