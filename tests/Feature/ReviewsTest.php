<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\SocialNetwork;
use App\Livewire\Client\Business\Automations;
use App\Livewire\Client\Reviews;
use App\Models\AiRun;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\BusinessProfile;
use App\Models\Organization;
use App\Models\Review;
use App\Models\SocialAccount;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AutomationNotice;
use App\Notifications\ReviewReceived;
use App\Services\Ai\Contracts\AiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * Reviews and reputation (D83): Google reviews come in, low ratings become tasks, replies are drafted
 * with AI on request and posted to Google, and the rating shows over time.
 */
class ReviewsTest extends TestCase
{
    use RefreshDatabase;

    private const LOCATION = 'accounts/1/locations/2';

    private User $owner;

    private Organization $org;

    private ScriptedAiProvider $ai;

    /** @var list<array<string, mixed>> what Google returns */
    private array $google = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'UTC'));
        $this->ai = new ScriptedAiProvider;
        $this->app->instance(AiProvider::class, $this->ai);
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->org = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->org->forceFill(['name' => 'Rivera Plumbing', 'timezone' => 'UTC', 'status' => 'active'])->save();
        $this->org = $this->org->fresh();
        BusinessProfile::create(['organization_id' => $this->org->id, 'display_name' => 'Rivera Plumbing', 'phone' => '(512) 555-0100']);
        SocialAccount::create(['organization_id' => $this->org->id, 'network' => SocialNetwork::GoogleBusiness, 'external_id' => self::LOCATION,
            'name' => 'Rivera Plumbing', 'access_token' => 'g-token', 'token_expires_at' => now()->addDay(), 'is_enabled' => true, 'status' => 'active']);
        Http::fake(function (Request $request) {
            if ($request->method() === 'PUT') {
                return Http::response(['comment' => $request['comment'], 'updateTime' => now()->toIso8601String()]);
            }

            return Http::response(['reviews' => $this->google, 'averageRating' => 4.6, 'totalReviewCount' => 31]);
        });
        $this->actingAs($this->owner);
    }

    private function review(string $id, string $stars, string $comment, string $at, ?string $reply = null): array
    {
        return array_filter(['name' => self::LOCATION.'/reviews/'.$id, 'reviewId' => $id, 'reviewer' => ['displayName' => 'Ana Lopez'],
            'starRating' => $stars, 'comment' => $comment, 'createTime' => $at, 'updateTime' => $at,
            'reviewReply' => $reply ? ['comment' => $reply, 'updateTime' => $at] : null]);
    }

    public function test_the_first_sync_imports_quietly_then_new_reviews_alert_the_team(): void
    {
        $this->google = [
            $this->review('old', 'FIVE', 'Great job!', '2026-06-01T10:00:00Z'),
            $this->review('answered', 'FOUR', 'Good work', '2026-07-01T10:00:00Z', 'Thanks Ana!'),
            $this->review('recent', 'THREE', "OK service (Translated by Google)\nServicio regular", '2026-10-10T10:00:00Z'),
        ];
        $this->artisan('reviews:sync')->assertSuccessful();

        $this->assertSame(['dismissed', 'replied', 'new'], Review::query()->orderBy('reviewed_at')->pluck('status')->all());
        $this->assertSame('OK service', Review::query()->where('rating', 3)->value('comment'));
        Notification::assertNothingSent();
        $account = SocialAccount::query()->sole();
        $this->assertSame(4.6, $account->meta['rating']);
        $this->assertSame(31, $account->meta['review_count']);

        // A new 1-star review an hour later: the team hears about it and gets a task; an automation runs.
        Automation::create(['organization_id' => $this->org->id, 'name' => 'Bad review alert', 'trigger' => 'review_received', 'delay_minutes' => 0,
            'action' => 'notify_team', 'action_config' => ['message' => 'Bad review from {first_name}: {reason}'],
            'conditions' => ['rules' => [['field' => 'review_rating', 'op' => 'low']]]]);
        $this->travel(1)->hours();
        array_unshift($this->google, $this->review('bad', 'ONE', 'Never showed up.', '2026-10-12T15:30:00Z'));
        $this->artisan('reviews:sync')->assertSuccessful();

        Notification::assertSentTo($this->owner, ReviewReceived::class);
        $task = Task::query()->sole();
        $this->assertSame('Reply to Ana Lopez\'s 1-star review', $task->title);
        $this->assertSame('high', $task->priority->value);
        $this->artisan('automations:run')->assertSuccessful();
        Notification::assertSentTo($this->owner, AutomationNotice::class, fn (AutomationNotice $n) => $n->text === 'Bad review from Ana: 1 stars: Never showed up.');
        $this->assertSame('done', AutomationRun::query()->sole()->status);

        // Running again changes nothing.
        $this->artisan('reviews:sync')->assertSuccessful();
        $this->assertSame(4, Review::query()->count());
        $this->assertSame(1, Task::query()->count());
    }

    public function test_replies_are_drafted_with_ai_and_posted_to_google(): void
    {
        $this->google = [$this->review('r1', 'TWO', 'Late and messy.', '2026-10-11T10:00:00Z')];
        $this->artisan('reviews:sync')->assertSuccessful();
        $review = Review::query()->sole();
        $this->ai->reply(json_encode(['reply' => 'Hi Ana, we\'re sorry we let you down. Please call us at (512) 555-0100. Rivera Plumbing']));

        $page = Livewire::test(Reviews::class)->assertSee('Late and messy.')->assertSee('Waiting for a reply')
            ->call('compose', $review->ulid)->call('draft')->assertHasNoErrors()
            ->assertSet('text', 'Hi Ana, we\'re sorry we let you down. Please call us at (512) 555-0100. Rivera Plumbing');
        $this->assertStringContainsString('Never mention private details', $this->ai->requests[0]->instructions);
        $this->assertStringContainsString('Stars: 2 of 5', $this->ai->requests[0]->messages[0]['content']);
        $this->assertSame(1, AiRun::query()->where('mode', 'review')->count());

        $page->set('text', 'Hi Ana, sorry about this. Please call us at (512) 555-0100. Rivera Plumbing')->call('post')->assertHasNoErrors();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://mybusiness.googleapis.com/v4/'.self::LOCATION.'/reviews/r1/reply'
            && $r['comment'] === 'Hi Ana, sorry about this. Please call us at (512) 555-0100. Rivera Plumbing');
        $review->refresh();
        $this->assertSame(Review::REPLIED, $review->status);
        $this->assertSame($this->owner->id, $review->replied_by_user_id);
        $this->assertNull($review->draft);

        // The monthly AI cap.
        config(['reviews.ai_monthly_drafts' => 1]);
        Livewire::test(Reviews::class)->set('filter', 'all')->call('compose', $review->ulid)->call('draft')->assertHasErrors('draft');
    }

    public function test_the_page_shows_the_rating_trend_and_results_show_the_month(): void
    {
        $this->google = [
            $this->review('a', 'FIVE', 'Great', '2026-10-05T10:00:00Z', 'Thanks'),
            $this->review('b', 'FOUR', 'Good', '2026-10-06T10:00:00Z'),
            $this->review('c', 'TWO', 'Meh', '2026-08-06T10:00:00Z'),
        ];
        $this->artisan('reviews:sync')->assertSuccessful();

        $summary = Livewire::test(Reviews::class)->assertSee('4.6')->viewData('summary');
        $this->assertSame(4.5, collect($summary['months'])->firstWhere('label', 'Oct')['average']);
        $this->assertNull(collect($summary['months'])->firstWhere('label', 'Sep')['average']);
        $this->assertSame(2, $summary['recent']);

        $this->get(route('app.results'))->assertOk()->assertSee('New Google reviews')->assertSee('averaging 4.5 stars');
    }

    public function test_review_automations_cant_message_customers_and_staff_cant_reply(): void
    {
        Livewire::test(Automations::class)->call('start')->set('form.name', 'Thanks')->set('form.trigger', 'review_received')
            ->set('form.steps.0.action', 'send_email')->set('form.steps.0.subject', 'x')->set('form.steps.0.body', 'y')
            ->call('save')->assertHasErrors('form.steps.0.action');

        $this->google = [$this->review('r1', 'FIVE', 'Great', '2026-10-11T10:00:00Z')];
        $this->artisan('reviews:sync')->assertSuccessful();
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->org->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->actingAs($staff);
        Livewire::test(Reviews::class)->assertSee('Great')->assertDontSee('Draft with AI')
            ->call('compose', Review::query()->value('ulid'))->assertForbidden();
    }

    public function test_without_google_it_asks_to_connect(): void
    {
        SocialAccount::query()->delete();
        config(['social.connectors.google.enabled' => true, 'social.connectors.google.client_id' => 'id', 'social.connectors.google.client_secret' => 'secret']);

        Livewire::test(Reviews::class)->assertSee('Connect your Google Business Profile')
            ->assertSee(route('app.social.connect', ['connector' => 'google', 'back' => 'reviews']), false);
    }
}
