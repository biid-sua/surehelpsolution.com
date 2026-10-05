<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Agent\Quality;
use App\Models\AuditLog;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\QaReview;
use App\Models\User;
use App\Notifications\QaReviewCompleted;
use App\Services\Quality\QualityReviews;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Call quality reviews (spec SUP-04, AGT-12).
 */
class CallQualityTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_MET = ['greeting' => 'met', 'accuracy' => 'met', 'booking_attempt' => 'met', 'tone' => 'met', 'compliance' => 'met'];

    private function business(string $name): Organization
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['name' => $name, 'setup_completed_at' => now()])->save();

        return $org->fresh();
    }

    private function agent(string $name, ?Organization $assignedTo = null, ?string $role = null): User
    {
        $user = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false, 'name' => $name]);
        if ($role) {
            $user->syncRoles([$role]);
        }
        if ($assignedTo) {
            $user->assignedOrganizations()->attach($assignedTo->id, ['source' => 'manual']);
        }
        $this->enableTwoFactor($user);

        return $user->fresh();
    }

    private function logCall(Organization $org, User $agent, string $when = 'now'): CallLog
    {
        $call = CallLog::create(['user_id' => $agent->id, 'organization_id' => $org->id, 'call_id' => CallLog::generateCallId(), 'call_date' => now()->toDateString(),
            'call_time' => '09:00', 'caller_name' => 'Ana Lopez', 'reason_for_call' => 'service-request', 'call_outcome' => 'other', 'agent_name' => $agent->name, 'status' => 'new']);
        $call->forceFill(['created_at' => now()->parse($when)])->save();

        return $call;
    }

    public function test_scorecard_maths_weights_optional_points_and_the_critical_rule(): void
    {
        $reviews = app(QualityReviews::class);

        $this->assertSame(100, $reviews->score(self::ALL_MET)['score']);

        // Partly on accuracy (weight 2): 14 of 16 points.
        $partly = $reviews->score(['accuracy' => 'partly'] + self::ALL_MET);
        $this->assertSame(88, $partly['score']);
        $this->assertTrue($partly['passed']);

        // "Doesn't apply" drops the booking attempt from the total.
        $this->assertSame(100, $reviews->score(['booking_attempt' => 'na'] + self::ALL_MET)['score']);

        // Missing compliance fails the review even at 75%+.
        $critical = $reviews->score(['compliance' => 'missed'] + self::ALL_MET);
        $this->assertSame(75, $critical['score']);
        $this->assertFalse($critical['passed']);

        $this->expectException(ValidationException::class);
        $reviews->score(['greeting' => 'na'] + self::ALL_MET);   // greeting always applies
    }

    public function test_the_daily_sample_picks_calls_per_agent_once(): void
    {
        $org = $this->business('Rivera Plumbing');
        $ana = $this->agent('Ana Agent', $org);
        $ben = $this->agent('Ben Agent', $org);
        $this->logCall($org, $ana, '-1 day');
        $this->logCall($org, $ana, '-1 day');
        $this->logCall($org, $ben, '-1 day');
        $this->logCall($org, $ben);   // today: not in yesterday's sample

        $this->artisan('quality:sample')->expectsOutputToContain('Added 2 calls');
        $this->assertSame(1, QaReview::where('agent_user_id', $ana->id)->count());
        $this->assertSame(1, QaReview::where('agent_user_id', $ben->id)->count());
        $this->assertSame(QaReview::SOURCE_SAMPLE, QaReview::first()->source);

        // Running again only adds Ana's other call (Ben has none left from that day).
        $this->artisan('quality:sample')->expectsOutputToContain('Added 1 call');
        $this->artisan('quality:sample')->expectsOutputToContain('Added 0 calls');
    }

    public function test_supervisors_score_their_businesses_calls_and_the_agent_reads_the_feedback(): void
    {
        Notification::fake();
        $org = $this->business('Rivera Plumbing');
        $other = $this->business('Other Co');
        $agent = $this->agent('Ana Agent', $org);
        $supervisor = $this->agent('Sue Supervisor', $org, 'agent_supervisor');
        $mine = $this->logCall($org, $agent);
        $theirs = $this->logCall($other, $this->agent('Zed Agent', $other));
        $own = $this->logCall($org, $supervisor);

        $this->actingAs($supervisor)->get(route('agent.quality'))->assertOk()->assertSee('To review')->assertSee('Team results');

        // Not other businesses' calls, and never your own.
        Livewire::test(Quality::class)->set('callId', $theirs->call_id)->call('pick')->assertHasErrors('callId')
            ->set('callId', $own->call_id)->call('pick')->assertHasErrors('callId');

        $component = Livewire::test(Quality::class)->set('callId', strtolower($mine->call_id))->call('pick')->assertHasNoErrors()
            ->assertSee('Call '.$mine->call_id)->assertSee('Must pass')->assertSeeHtml('value="partly"');
        $review = QaReview::where('call_log_id', $mine->id)->sole();
        $this->assertSame(QaReview::SOURCE_MANUAL, $review->source);

        $component->call('save')->assertHasErrors(['marks.greeting', 'marks.compliance', 'improvements'])
            ->set('marks', ['accuracy' => 'partly'] + self::ALL_MET)->call('save')->assertHasErrors('improvements')->assertHasNoErrors('marks.tone')
            ->set('improvements', 'Read the address back to the caller.')->call('save')->assertHasNoErrors();

        $review->refresh();
        $this->assertSame(88, $review->score);
        $this->assertTrue($review->passed);
        $this->assertSame($supervisor->id, $review->reviewer_id);
        $this->assertSame('Partly', QaReview::MARKS[$review->scores['accuracy']['mark']]);
        $this->assertTrue(AuditLog::where('action', 'qa_review.completed')->exists());
        Notification::assertSentTo($agent, QaReviewCompleted::class, fn (QaReviewCompleted $n) => str_contains($n->toArray($agent)['url'], $review->ulid));

        Livewire::test(Quality::class, ['tab' => 'team'])->assertSee('Ana Agent')->assertSee('88%');

        // The agent opens it from the notification, reads it and confirms.
        $this->actingAs($agent)->get(route('agent.quality', ['review' => $review->ulid]))->assertOk()
            ->assertSee('Read the address back to the caller.')->assertSee('Partly')->assertDontSee('To review');
        Livewire::test(Quality::class, ['review' => $review->ulid])->assertSee('Got it')->assertDontSee('Save review')->call('acknowledge');
        $this->assertNotNull($review->fresh()->acknowledged_at);
        Livewire::test(Quality::class)->assertSee('88%');

        // Agents can't score, or open someone else's review.
        Livewire::test(Quality::class)->set('callId', $mine->call_id)->call('pick')->assertForbidden();
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(Quality::class)->call('open', QaReview::create(['call_log_id' => $theirs->id, 'organization_id' => $other->id, 'agent_user_id' => $theirs->user_id, 'source' => 'sample'])->ulid);
    }

    public function test_platform_managers_see_everything_and_businesses_never_see_reviews(): void
    {
        $org = $this->business('Rivera Plumbing');
        $agent = $this->agent('Ana Agent', $org);
        $call = $this->logCall($org, $agent, '-1 day');
        app(QualityReviews::class)->drawSample(now()->subDay());

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $admin->syncRoles(['operations_manager']);
        $this->enableTwoFactor($admin);
        $this->actingAs($admin)->get(route('admin.home'))->assertSee('Call quality');
        $this->get(route('agent.quality'))->assertOk()->assertSee($call->call_id)->assertSee('At random');

        $support = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $support->syncRoles(['support_agent']);
        $this->assertFalse(app(QualityReviews::class)->canReviewAny($support));

        $owner = $org->members()->first();
        $this->assertFalse($owner->hasPermissionIn('qa.review', $org));
        $this->actingAs($owner)->get(route('agent.quality'))->assertForbidden();
    }
}
