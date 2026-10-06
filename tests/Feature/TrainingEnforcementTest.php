<?php

namespace Tests\Feature;

use App\Actions\Assignments\AssignAgent;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\LessonType;
use App\Enums\NotificationEvent;
use App\Livewire\Account\Notifications;
use App\Livewire\Agent\Workspace;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Models\TrainingAttempt;
use App\Models\TrainingCertificate;
use App\Models\TrainingCourse;
use App\Models\TrainingLesson;
use App\Models\TrainingModule;
use App\Models\TrainingQuestion;
use App\Models\User;
use App\Notifications\CertificationActivity;
use App\Notifications\TrainingActivity;
use App\Services\Training\CoursePublisher;
use App\Services\Training\LearningProgress;
use App\Services\Training\TrainingAssigner;
use App\Services\Training\TrainingRecommendations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Agent University A-4 (D43, brief §1.14–1.15, §3.1, §8, §12): reminders, readiness enforcement
 * on the web and the API, recommendations with reasons, and the training API with the same
 * company boundary as the portal.
 */
class TrainingEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agent;

    private Organization $abc;

    private Organization $xyz;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->admin = $this->user('admin', 'super_admin', ['name' => 'Ada Admin']);
        $this->agent = $this->user('agent', 'agent', ['name' => 'Maria Agent']);
        $this->abc = $this->company('ABC Plumbing');
        $this->xyz = $this->company('XYZ Cleaning');
        app(AssignAgent::class)->handle($this->abc, $this->agent, $this->admin);
    }

    private function user(string $portal, string $role, array $extra = []): User
    {
        $user = User::factory()->create(array_merge(['role' => $portal, 'is_active' => true, 'must_change_password' => false], $extra));
        $user->syncRoles([$role]);

        return $user;
    }

    private function company(string $name): Organization
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->update(['name' => $name]);

        return $org->fresh();
    }

    private function course(string $title, ?Organization $for = null, array $extra = []): TrainingCourse
    {
        $course = TrainingCourse::create(array_merge(['title' => $title, 'organization_id' => $for?->id], $extra));
        $module = TrainingModule::create(['course_id' => $course->id, 'title' => 'Basics']);
        TrainingLesson::create(['course_id' => $course->id, 'module_id' => $module->id, 'title' => 'Read me', 'type' => LessonType::Text, 'body' => 'Say the company name.', 'position' => 1]);
        $quiz = TrainingLesson::create(['course_id' => $course->id, 'module_id' => $module->id, 'title' => 'Check', 'type' => LessonType::Quiz, 'pass_percent' => 100, 'max_attempts' => 3, 'position' => 2]);
        TrainingQuestion::create(['lesson_id' => $quiz->id, 'type' => 'single', 'prompt' => 'First words?', 'explanation' => 'Always the business name.', 'options' => [
            ['id' => 'a', 'label' => 'Company name', 'correct' => true], ['id' => 'b', 'label' => 'Hello?', 'correct' => false],
        ]]);
        app(CoursePublisher::class)->publish($course, $this->admin);

        return $course->fresh();
    }

    private function require(TrainingCourse $course, Organization $for, string $enforcement): void
    {
        app(TrainingAssigner::class)->createRule($course, $this->admin, ['scope' => 'company', 'organization_id' => $for->id, 'enforcement' => $enforcement, 'due_days' => 7]);
    }

    private function finish(TrainingCourse $course, User $who): void
    {
        $learning = app(LearningProgress::class);
        $assignment = $learning->enrol($who, $course);
        $version = $learning->begin($assignment);
        [$text, $quiz] = $version->lessonUlids();
        $learning->completeLesson($assignment, $version, $text, 60);
        $attempt = $learning->startAttempt($assignment->fresh(), $version, $quiz);
        $learning->submitAttempt($attempt, $version, [$attempt->question_ulids[0] => 'a']);
    }

    private function callPayload(): array
    {
        return [
            'client_id' => (string) $this->abc->owner_user_id, 'call_date' => now()->toDateString(), 'call_time' => '10:00',
            'caller_name' => 'Pat Caller', 'caller_phone' => '555-0100', 'reason_for_call' => 'general-inquiry',
            'call_outcome' => 'resolved-by-agent', 'agent_name' => 'Maria', 'status' => 'new',
        ];
    }

    // Reminders -----------------------------------------------------------------

    public function test_new_training_is_announced_and_optional_training_stays_in_the_app(): void
    {
        $required = $this->course('ABC booking guide', $this->abc);
        $this->require($required, $this->abc, 'warning');
        Notification::assertSentTo($this->agent, TrainingActivity::class, function (TrainingActivity $n) {
            return $n->change === TrainingActivity::ASSIGNED && $n->toArray($this->agent)['title'] === 'You have new required training for ABC Plumbing'
                && in_array('mail', $n->via($this->agent), true);
        });

        $optional = $this->course('Upselling');
        app(TrainingAssigner::class)->createRule($optional, $this->admin, ['scope' => 'agent', 'agent_user_id' => $this->agent->id, 'is_required' => false]);
        Notification::assertSentTo($this->agent, TrainingActivity::class, fn (TrainingActivity $n) => $n->assignment->course_id === $optional->id
            && $n->via($this->agent) === ['database']);

        // Becoming required later is announced again.
        app(TrainingAssigner::class)->createRule($optional, $this->admin, ['scope' => 'everyone', 'is_required' => true]);
        Notification::assertSentToTimes($this->agent, TrainingActivity::class, 3);

        // Whoever gave it hears when it's done.
        $this->finish($optional, $this->agent);
        Notification::assertSentTo($this->admin, TrainingActivity::class, fn (TrainingActivity $n) => $n->change === TrainingActivity::COMPLETED);
    }

    public function test_agents_choose_how_they_hear_about_training(): void
    {
        $this->actingAs($this->agent)->get(route('account.notifications'))->assertOk()->assertSee('New training')->assertDontSee('Training completed by an agent');
        Livewire::test(Notifications::class)->set('preferences.TrainingDue.mail', false)->call('save')->assertHasNoErrors();
        $this->assertSame(['database'], $this->agent->fresh()->notificationChannelsFor(NotificationEvent::TrainingDue));

        $this->actingAs($this->abc->owner)->get(route('account.notifications'))->assertNotFound();
    }

    public function test_reminders_are_sent_once_each(): void
    {
        $course = $this->course('Security', null, ['valid_for_months' => 1, 'issues_certificate' => true, 'certificate_name' => 'Security Aware']);
        app(TrainingAssigner::class)->give($course, $this->agent, $this->admin, ['is_required' => true, 'due_at' => now()->addDay()]);

        $this->artisan('training:sweep')->assertSuccessful();
        $this->artisan('training:sweep')->assertSuccessful();
        Notification::assertSentToTimes($this->agent, TrainingActivity::class, 2); // assigned + due soon (once)

        $this->travel(2)->days();
        $this->artisan('training:sweep')->assertSuccessful();
        $this->artisan('training:sweep')->assertSuccessful();
        Notification::assertSentTo($this->agent, TrainingActivity::class, fn (TrainingActivity $n) => $n->change === TrainingActivity::OVERDUE
            && str_contains($n->toArray($this->agent)['title'], 'overdue'));
        Notification::assertSentToTimes($this->admin, TrainingActivity::class, 1); // the giver hears once
        $this->assertDatabaseHas('audit_logs', ['action' => 'training.overdue']);

        // Certificates: expiring (30 days), then expired, once each.
        $this->finish($course, $this->agent);
        $this->travel(2)->days();
        $this->artisan('training:sweep')->assertSuccessful();
        Notification::assertSentTo($this->agent, CertificationActivity::class, fn (CertificationActivity $n) => $n->change === CertificationActivity::EXPIRING);
        $this->travel(40)->days();
        $this->artisan('training:sweep')->assertSuccessful();
        $this->artisan('training:sweep')->assertSuccessful();
        Notification::assertSentToTimes($this->agent, CertificationActivity::class, 2);
        $this->assertSame('expired', TrainingCertificate::sole()->effectiveStatus());
    }

    // Enforcement ---------------------------------------------------------------

    public function test_restricted_training_stops_calls_and_bookings_but_not_the_rest(): void
    {
        $course = $this->course('ABC booking guide', $this->abc);
        $this->require($course, $this->abc, 'restricted');

        Sanctum::actingAs($this->agent);
        $this->postJson('/api/v1/agent/call-logs', $this->callPayload())->assertStatus(422)
            ->assertJsonPath('errors.client_id.0', 'Finish the required training for ABC Plumbing before taking its calls or booking appointments.');
        $this->getJson("/api/v1/agent/companies/{$this->abc->ulid}/customers")->assertOk();

        $this->actingAs($this->agent);
        $this->get(route('agent.businesses.show', $this->abc->ulid))->assertOk()->assertSee('before taking its calls');
        $this->get(route('agent.businesses.customers', $this->abc->ulid))->assertOk();
        Livewire::test(Workspace::class, ['organization' => $this->abc])
            ->set('entry.reason', 'general-inquiry')->set('entry.outcome', 'resolved-by-agent')->call('save')->assertHasErrors('training');
        $this->assertSame(0, CallLog::withoutGlobalScopes()->count());

        // Done: everything works again. Platform staff were never limited.
        $this->finish($course, $this->agent);
        Sanctum::actingAs($this->agent->fresh());
        $this->postJson('/api/v1/agent/call-logs', $this->callPayload())->assertOk();
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/agent/call-logs', $this->callPayload())->assertOk();
    }

    public function test_blocking_training_leaves_only_the_company_training_open(): void
    {
        $course = $this->course('ABC must-know', $this->abc);
        $this->require($course, $this->abc, 'blocking');
        $customer = Customer::create(['organization_id' => $this->abc->id, 'first_name' => 'Pat', 'last_name' => 'Caller', 'phone' => '555-0100']);

        $this->actingAs($this->agent);
        foreach ([route('agent.businesses.show', $this->abc->ulid), route('agent.businesses.customers', $this->abc->ulid),
            route('agent.businesses.customers.show', [$this->abc->ulid, $customer->ulid]), route('agent.businesses.tasks', $this->abc->ulid)] as $url) {
            $this->get($url)->assertRedirect(route('agent.businesses.training', $this->abc->ulid));
        }
        $this->get(route('agent.businesses.training', $this->abc->ulid))->assertOk()->assertSee('ABC must-know');
        $this->get(route('agent.university.course', $course->ulid))->assertOk();

        Sanctum::actingAs($this->agent);
        $this->getJson("/api/v1/agent/companies/{$this->abc->ulid}/customers")->assertForbidden()
            ->assertJsonPath('message', 'Finish the required training for ABC Plumbing to start working for it. Until then only its training is open.')
            ->assertJsonPath('code', 'training_required')->assertJsonPath('missing', ['ABC must-know']);
        $this->postJson('/api/v1/agent/call-logs', $this->callPayload())->assertStatus(422);

        // Other companies aren't affected by ABC's requirement.
        app(AssignAgent::class)->handle($this->xyz, $this->agent, $this->admin);
        $this->actingAs($this->agent->fresh())->get(route('agent.businesses.customers', $this->xyz->ulid))->assertOk();

        $this->finish($course, $this->agent);
        $this->actingAs($this->agent->fresh())->get(route('agent.businesses.customers', $this->abc->ulid))->assertOk();
    }

    public function test_warning_only_reminds(): void
    {
        $this->require($this->course('ABC tips', $this->abc), $this->abc, 'warning');

        $this->actingAs($this->agent)->get(route('agent.businesses.customers', $this->abc->ulid))->assertOk()->assertSee('Training required for ABC Plumbing: ABC tips');
        Sanctum::actingAs($this->agent);
        $this->postJson('/api/v1/agent/call-logs', $this->callPayload())->assertOk();
    }

    // Recommendations -----------------------------------------------------------

    public function test_recommendations_explain_themselves(): void
    {
        $company = $this->course('ABC service guide', $this->abc);
        $failed = $this->course('Escalations');
        $suggested = $this->course('Upselling');
        $this->course('XYZ secrets', $this->xyz);

        $learning = app(LearningProgress::class);
        $assignment = $learning->enrol($this->agent, $failed);
        $version = $learning->begin($assignment);
        $attempt = $learning->startAttempt($assignment, $version, $version->lessonUlids()[1]);
        $learning->submitAttempt($attempt, $version, [$attempt->question_ulids[0] => 'b']);
        app(TrainingAssigner::class)->give($suggested, $this->agent, $this->admin, ['is_required' => false]);

        $picks = app(TrainingRecommendations::class)->for($this->agent)->mapWithKeys(fn ($p) => [$p['course']->title => $p['reason']]);
        $this->assertSame('You haven\'t passed the quiz yet. Review the lessons and try again.', $picks['Escalations']);
        $this->assertSame('You recently started supporting ABC Plumbing.', $picks['ABC service guide']);
        $this->assertSame('Suggested by Ada Admin.', $picks['Upselling']);
        $this->assertArrayNotHasKey('XYZ secrets', $picks->all());
        $this->assertSame(['retry', 'company', 'suggested'], app(TrainingRecommendations::class)->for($this->agent)->pluck('kind')->take(3)->all());
        $this->actingAs($this->agent)->get(route('agent.university', ['tab' => 'recommended']))->assertOk()->assertSee('Picked for you')->assertSee('You recently started supporting ABC Plumbing.');
        $this->assertNotNull($company);
    }

    // Training API --------------------------------------------------------------

    public function test_the_training_api_takes_a_course_end_to_end_without_revealing_answers(): void
    {
        $course = $this->course('Call handling', null, ['issues_certificate' => true, 'certificate_name' => 'Certified']);
        Sanctum::actingAs($this->agent);

        $this->getJson('/api/v1/agent/training')->assertOk()->assertJsonPath('data.recommended.0.title', 'Call handling');
        $start = $this->postJson("/api/v1/agent/training/courses/{$course->ulid}/start")->assertOk()->json('data');
        $this->getJson("/api/v1/agent/training/courses/{$course->ulid}/lessons/{$start['next_lesson']}")->assertOk()
            ->assertJsonPath('data.lesson.body_html', "<p>Say the company name.</p>\n");
        $this->postJson("/api/v1/agent/training/courses/{$course->ulid}/lessons/{$start['next_lesson']}/complete", ['seconds' => 999999])->assertStatus(422);
        $this->postJson("/api/v1/agent/training/courses/{$course->ulid}/lessons/{$start['next_lesson']}/complete", ['seconds' => 30])
            ->assertOk()->assertJsonPath('data.assignment.progress_percent', 50);

        $quiz = $this->getJson("/api/v1/agent/training/courses/{$course->ulid}")->json('data.course.modules.0.lessons.1.id');
        $attempt = $this->postJson("/api/v1/agent/training/courses/{$course->ulid}/lessons/{$quiz}/attempts")->assertOk();
        $this->assertStringNotContainsString('"correct":true', $attempt->getContent());
        $question = $attempt->json('data.attempt.questions.0.id');

        $this->postJson('/api/v1/agent/training/attempts/'.$attempt->json('data.attempt.id').'/submit', ['answers' => [$question => 'b']])->assertOk()
            ->assertJsonPath('data.attempt.passed', false)->assertJsonPath('data.attempt.questions.0.explanation', 'Always the business name.')
            ->assertJsonPath('data.attempts_left', 2);
        $retry = $this->postJson("/api/v1/agent/training/courses/{$course->ulid}/lessons/{$quiz}/attempts")->json('data.attempt.id');
        $this->postJson("/api/v1/agent/training/attempts/{$retry}/submit", ['answers' => [$question => 'a']])->assertOk()
            ->assertJsonPath('data.attempt.passed', true)->assertJsonPath('data.assignment.status', 'completed');
        $this->getJson('/api/v1/agent/training/certificates')->assertOk()->assertJsonPath('data.certificates.0.name', 'Certified');
    }

    public function test_the_training_api_keeps_the_company_boundary(): void
    {
        $theirs = $this->course('XYZ secrets', $this->xyz);
        $lesson = $theirs->version(1)->lessonUlids()[0];
        Sanctum::actingAs($this->agent);

        $this->getJson("/api/v1/agent/training/courses/{$theirs->ulid}")->assertNotFound();
        $this->postJson("/api/v1/agent/training/courses/{$theirs->ulid}/start")->assertNotFound();
        $this->getJson("/api/v1/agent/training/courses/{$theirs->ulid}/lessons/{$lesson}")->assertNotFound();
        $this->postJson("/api/v1/agent/training/courses/{$theirs->ulid}/lessons/{$lesson}/complete")->assertNotFound();
        $this->getJson("/api/v1/agent/training/courses/{$theirs->ulid}/v1/files/{$lesson}")->assertNotFound();
        $this->getJson('/api/v1/agent/training/courses/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
        $this->getJson('/api/v1/agent/training')->assertOk()->assertDontSee('XYZ secrets');

        // Someone else's attempt is "not found".
        $other = $this->user('agent', 'agent');
        app(AssignAgent::class)->handle($this->xyz, $other, $this->admin);
        $learning = app(LearningProgress::class);
        $assignment = $learning->enrol($other, $theirs);
        $version = $learning->begin($assignment);
        $attempt = $learning->startAttempt($assignment, $version, $version->lessonUlids()[1]);
        $this->postJson("/api/v1/agent/training/attempts/{$attempt->ulid}/submit", ['answers' => [$attempt->question_ulids[0] => 'a']])->assertNotFound();
        $this->assertNull(TrainingAttempt::find($attempt->id)->submitted_at);

        // Business owners have no training API.
        Sanctum::actingAs($this->abc->owner);
        $this->getJson('/api/v1/agent/training')->assertForbidden();
        $this->assertSame(0, TrainingAssignment::query()->where('agent_user_id', $this->abc->owner_user_id)->count());
    }
}
