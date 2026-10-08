<?php

namespace Tests\Feature;

use App\Actions\Assignments\AssignAgent;
use App\Actions\Assignments\ChangeAssignment;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\LessonType;
use App\Livewire\Agent\Training\CourseEditor;
use App\Livewire\Agent\Training\Courses;
use App\Livewire\Agent\Training\Paths;
use App\Livewire\Agent\University\Learning;
use App\Livewire\Agent\University\Lesson;
use App\Models\AgentAssignment;
use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Models\TrainingAttempt;
use App\Models\TrainingCertificate;
use App\Models\TrainingCompletion;
use App\Models\TrainingCourse;
use App\Models\TrainingLesson;
use App\Models\TrainingModule;
use App\Models\TrainingPath;
use App\Models\TrainingQuestion;
use App\Models\User;
use App\Services\Training\CoursePublisher;
use App\Services\Training\LearningProgress;
use App\Services\Training\TrainingAssigner;
use App\Services\Training\TrainingReadiness;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Agent University (spec §20B, D42–D43): versions, rules, learning, quizzes, certificates,
 * recertification, readiness, and the company boundary for training (brief §2.12–2.13).
 */
class AgentUniversityTest extends TestCase
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
        $this->admin = $this->user('admin', 'super_admin');
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

    /** A course with a reading lesson and a two-question quiz (pass 50%), published. */
    private function course(string $title, ?Organization $for = null, array $extra = []): TrainingCourse
    {
        $course = TrainingCourse::create(array_merge(['title' => $title, 'organization_id' => $for?->id, 'created_by_user_id' => $this->admin->id], $extra));
        $module = TrainingModule::create(['course_id' => $course->id, 'title' => 'Basics']);
        TrainingLesson::create(['course_id' => $course->id, 'module_id' => $module->id, 'title' => 'Read me', 'type' => LessonType::Text, 'body' => '## Greeting'."\n".'Say the company name.', 'position' => 1]);
        $quiz = TrainingLesson::create(['course_id' => $course->id, 'module_id' => $module->id, 'title' => 'Check', 'type' => LessonType::Quiz, 'pass_percent' => 50, 'max_attempts' => 2, 'position' => 2]);
        TrainingQuestion::create(['lesson_id' => $quiz->id, 'type' => 'single', 'prompt' => 'First words?', 'options' => [
            ['id' => 'a', 'label' => 'Company name', 'correct' => true], ['id' => 'b', 'label' => 'Hello?', 'correct' => false],
        ], 'position' => 1]);
        TrainingQuestion::create(['lesson_id' => $quiz->id, 'type' => 'multiple', 'prompt' => 'Ask for?', 'options' => [
            ['id' => 'n', 'label' => 'Name', 'correct' => true], ['id' => 'p', 'label' => 'Phone', 'correct' => true], ['id' => 'c', 'label' => 'Card number', 'correct' => false],
        ], 'position' => 2]);
        app(CoursePublisher::class)->publish($course, $this->admin);

        return $course->fresh();
    }

    /** @return array{text: string, quiz: string} */
    private function lessons(TrainingCourse $course): array
    {
        $lessons = $course->version($course->current_version)->lessons();

        return ['text' => $lessons[0]['ulid'], 'quiz' => $lessons[1]['ulid']];
    }

    /** Takes the whole course as the agent; $right decides the quiz answers. */
    private function take(TrainingCourse $course, User $who, bool $right = true): TrainingAssignment
    {
        $learning = app(LearningProgress::class);
        $assignment = $learning->enrol($who, $course);
        $version = $learning->begin($assignment);
        ['text' => $text, 'quiz' => $quiz] = $this->lessons($course);
        $learning->completeLesson($assignment, $version, $text, 120);
        $attempt = $learning->startAttempt($assignment->fresh(), $version, $quiz);
        $q = $attempt->question_ulids;
        $byPrompt = collect($version->lesson($quiz)['quiz']['questions'])->keyBy('ulid');
        $answers = collect($q)->mapWithKeys(fn ($u) => [$u => $byPrompt[$u]['type'] === 'multiple'
            ? ($right ? ['n', 'p'] : ['c']) : ($right ? 'a' : 'b')])->all();
        $learning->submitAttempt($attempt, $version, $answers);

        return $assignment->fresh();
    }

    // Publishing and versions -------------------------------------------------

    public function test_a_draft_must_be_complete_before_it_can_be_published(): void
    {
        $course = TrainingCourse::create(['title' => 'Empty']);
        $this->assertContains('Add at least one lesson.', app(CoursePublisher::class)->problems($course));

        $module = TrainingModule::create(['course_id' => $course->id, 'title' => 'M']);
        $quiz = TrainingLesson::create(['course_id' => $course->id, 'module_id' => $module->id, 'title' => 'Q', 'type' => LessonType::Quiz]);
        TrainingQuestion::create(['lesson_id' => $quiz->id, 'type' => 'single', 'prompt' => '?', 'options' => [['id' => 'a', 'label' => 'A', 'correct' => false], ['id' => 'b', 'label' => 'B', 'correct' => false]]]);
        $this->assertSame(['Quiz "Q", question 1: mark the correct answer.'], app(CoursePublisher::class)->problems($course->fresh()));

        $this->expectException(ValidationException::class);
        app(CoursePublisher::class)->publish($course->fresh(), $this->admin);
    }

    public function test_learners_take_the_published_version_and_draft_edits_wait_for_the_next_one(): void
    {
        $course = $this->course('Call handling');
        $this->assertSame(1, $course->current_version);
        $this->assertFalse($course->hasUnpublishedChanges());

        TrainingLesson::query()->where('title', 'Read me')->update(['body' => 'SECRET DRAFT TEXT']);
        $course->touchDraft($this->admin);
        $text = $this->lessons($course)['text'];

        $this->actingAs($this->agent)->get(route('agent.university.lesson', [$course->ulid, $text]))
            ->assertOk()->assertSee('Say the company name.')->assertDontSee('SECRET DRAFT TEXT');
        $this->assertTrue($course->fresh()->hasUnpublishedChanges());

        app(CoursePublisher::class)->publish($course->fresh(), $this->admin, 'Shorter greeting');
        $this->actingAs($this->agent)->get(route('agent.university.course', $course->ulid))->assertOk();
        $this->assertDatabaseHas('training_course_versions', ['course_id' => $course->id, 'version' => 2, 'change_note' => 'Shorter greeting']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'training.published', 'subject_label' => 'Call handling v2']);
    }

    public function test_a_retake_required_version_reopens_completions_but_keeps_the_record(): void
    {
        $course = $this->course('Escalations');
        $done = $this->take($course, $this->agent);
        $this->assertTrue($done->isDone());

        app(CoursePublisher::class)->publish($course, $this->admin, 'New after-hours steps', retakeRequired: true);
        $row = $done->fresh();
        $this->assertFalse($row->isDone());
        $this->assertSame(2, $row->required_version);
        $this->assertSame(1, TrainingCompletion::query()->where('assignment_id', $row->id)->count(), 'the v1 completion is kept');

        // Taking it again records version 2.
        $again = $this->take($course->fresh(), $this->agent);
        $this->assertTrue($again->isDone());
        $this->assertSame(2, $again->completed_version);
        $this->assertSame([1, 2], TrainingCompletion::query()->where('assignment_id', $row->id)->orderBy('id')->pluck('course_version')->all());
    }

    // Learning, quizzes and certificates --------------------------------------

    public function test_progress_quiz_attempts_and_certificate(): void
    {
        $course = $this->course('Virtual Receptionist Fundamentals', null, ['issues_certificate' => true, 'certificate_name' => 'Certified Receptionist', 'valid_for_months' => 12]);
        $learning = app(LearningProgress::class);
        $assignment = $learning->enrol($this->agent, $course);
        $version = $learning->begin($assignment);
        ['text' => $text, 'quiz' => $quiz] = $this->lessons($course);

        $learning->completeLesson($assignment, $version, $text, 90);
        $this->assertSame(50, $assignment->fresh()->progress_percent);
        $this->assertSame('in_progress', $assignment->fresh()->effectiveStatus());

        // A wrong attempt: recorded, not passed, one attempt left.
        $this->take($course, $this->agent, right: false);
        $this->assertSame(1, $learning->attemptsLeft($assignment->fresh(), $version, $quiz));
        $this->assertFalse($assignment->fresh()->isDone());

        // Second wrong attempt uses the last one; the supervisor grants another.
        $this->take($course, $this->agent, right: false);
        $this->assertSame(0, $learning->attemptsLeft($assignment->fresh(), $version, $quiz));
        try {
            $learning->startAttempt($assignment->fresh(), $version, $quiz);
            $this->fail('No attempts should be left');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        app(TrainingAssigner::class)->allowAnotherAttempt($assignment->fresh(), $this->admin);

        $done = $this->take($course, $this->agent);
        $this->assertTrue($done->isDone());
        $this->assertSame(100, $done->best_score);
        $this->assertSame(3, $done->attempts()->whereNotNull('submitted_at')->count(), 'every attempt is kept');

        $certificate = TrainingCertificate::sole();
        $this->assertSame('Certified Receptionist', $certificate->name);
        $this->assertSame('active', $certificate->effectiveStatus());
        $this->assertTrue($certificate->expires_at->between(now()->addMonths(12)->subMinute(), now()->addMonths(12)->addMinute()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'training.completed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'training.certificate_issued']);
        $this->actingAs($this->agent)->get(route('agent.university.certificate', $certificate->ulid))->assertOk()->assertSee($certificate->number)->assertSee('Maria Agent');
    }

    public function test_the_quiz_page_never_reveals_correct_answers(): void
    {
        $course = $this->course('Privacy basics');
        $quiz = $this->lessons($course)['quiz'];

        $this->actingAs($this->agent);
        $page = Livewire::test(Lesson::class, ['course' => $course, 'lesson' => $quiz])->call('startQuiz')->assertSee('First words?');
        $this->assertStringNotContainsString('"correct"', $page->html());
        $this->assertStringNotContainsString('correct', json_encode($page->snapshot['data']));

        // Answers are checked on the server; an unanswered question is refused.
        $page->call('submitQuiz')->assertHasErrors();
        $attempt = TrainingAttempt::sole();
        $answers = collect($attempt->question_ulids)->mapWithKeys(fn ($u) => [$u => TrainingQuestion::where('ulid', $u)->sole()->type->allowsMany() ? ['n', 'p'] : 'a'])->all();
        $page->set('answers', $answers)->call('submitQuiz')->assertHasNoErrors()->assertSee('Passed');
    }

    public function test_an_expired_completion_needs_recertification_from_scratch(): void
    {
        $course = $this->course('Security', null, ['valid_for_months' => 12]);
        $this->take($course, $this->agent);
        $row = TrainingAssignment::sole();
        $this->assertTrue($row->isDone());

        $this->travel(13)->months();
        $this->assertSame('expired', $row->fresh()->effectiveStatus());
        $learning = app(LearningProgress::class);
        $version = $learning->begin($row->fresh());
        $this->assertSame([], $learning->completedLessons($row->fresh(), $version->version), 'a new cycle starts empty');
        $this->assertSame(2, $learning->attemptsLeft($row->fresh(), $version, $this->lessons($course)['quiz']), 'attempts start again');

        $this->take($course, $this->agent);
        $this->assertTrue($row->fresh()->isDone());
        $this->assertSame(2, TrainingCompletion::query()->count());
    }

    // Rules and assignment ----------------------------------------------------

    public function test_company_training_follows_company_assignment(): void
    {
        $course = $this->course('ABC Plumbing booking guide', $this->abc);
        app(TrainingAssigner::class)->createRule($course, $this->admin, ['scope' => 'company', 'organization_id' => $this->abc->id, 'due_days' => 7, 'enforcement' => 'restricted']);

        $row = TrainingAssignment::sole();
        $this->assertSame($this->agent->id, $row->agent_user_id);
        $this->assertTrue($row->is_required);
        $this->assertTrue($row->due_at->isSameDay(now()->addDays(7)));

        $readiness = app(TrainingReadiness::class)->for($this->agent, $this->abc);
        $this->assertFalse($readiness['ready']);
        $this->assertSame(['ABC Plumbing booking guide'], $readiness['missing']);
        $this->assertSame('restricted', $readiness['enforcement']);

        // A new agent assigned to the company gets the training straight away.
        $tom = $this->user('agent', 'agent', ['name' => 'Tom']);
        app(AssignAgent::class)->handle($this->abc, $tom, $this->admin);
        $this->assertDatabaseHas('training_assignments', ['course_id' => $course->id, 'agent_user_id' => $tom->id, 'is_required' => true]);

        $this->take($course, $this->agent);
        $this->assertTrue(app(TrainingReadiness::class)->for($this->agent, $this->abc)['ready']);
        $this->assertTrue(app(TrainingReadiness::class)->for($this->agent, $this->xyz)['ready'], 'nothing is required for XYZ');
    }

    public function test_a_second_rule_only_tightens(): void
    {
        $course = $this->course('Communication');
        $assigner = app(TrainingAssigner::class);
        $assigner->createRule($course, $this->admin, ['scope' => 'agent', 'agent_user_id' => $this->agent->id, 'is_required' => false, 'due_days' => 30]);
        $assigner->createRule($course, $this->admin, ['scope' => 'everyone', 'is_required' => true, 'priority' => 'high', 'due_days' => 10]);
        $assigner->createRule($course, $this->admin, ['scope' => 'everyone', 'is_required' => false, 'priority' => 'low', 'due_days' => 60]);

        $row = TrainingAssignment::sole();
        $this->assertTrue($row->is_required);
        $this->assertSame('high', $row->priority);
        $this->assertTrue($row->due_at->isSameDay(now()->addDays(10)));
    }

    public function test_removing_training_keeps_records_and_needs_a_reason(): void
    {
        $course = $this->course('Upselling');
        app(TrainingAssigner::class)->createRule($course, $this->admin, ['scope' => 'agent', 'agent_user_id' => $this->agent->id]);
        $row = TrainingAssignment::sole();

        try {
            app(TrainingAssigner::class)->revoke($row, $this->admin, ' ');
            $this->fail('A reason is required');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        app(TrainingAssigner::class)->revoke($row, $this->admin, 'No longer selling add-ons');
        $this->assertSame('revoked', $row->fresh()->effectiveStatus());
        $this->assertDatabaseHas('audit_logs', ['action' => 'training.revoked']);
    }

    // Company boundary --------------------------------------------------------

    public function test_another_companys_training_is_invisible_everywhere(): void
    {
        $theirs = $this->course('XYZ Cleaning secrets', $this->xyz);
        $lesson = $this->lessons($theirs)['text'];
        TrainingLesson::query()->where('course_id', $theirs->id)->where('type', 'text')->update(['type' => 'pdf', 'file_disk' => 'local', 'file_path' => 'training/x.pdf', 'file_name' => 'x.pdf', 'file_mime' => 'application/pdf']);
        Storage::disk('local')->put('training/x.pdf', '%PDF-1.4');
        app(CoursePublisher::class)->publish($theirs->fresh(), $this->admin);
        $theirs = $theirs->fresh();
        $ours = $this->course('ABC Plumbing guide', $this->abc);

        $this->actingAs($this->agent);
        $this->get(route('agent.university.course', $theirs->ulid))->assertNotFound();
        $this->get(route('agent.university.lesson', [$theirs->ulid, $lesson]))->assertNotFound();
        $this->get(route('agent.university.file', [$theirs->ulid, 2, $lesson]))->assertNotFound();
        $this->get(route('agent.businesses.training', $this->xyz->ulid))->assertNotFound();
        $this->get(route('agent.university', ['tab' => 'recommended']))->assertOk()->assertSee('ABC Plumbing guide')->assertDontSee('XYZ Cleaning secrets');
        $this->get(route('agent.university.course', $ours->ulid))->assertOk();

        try {
            app(LearningProgress::class)->enrol($this->agent, $theirs);
            $this->fail('Enrolling in another company\'s course must fail');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        // An admin with access gets the file.
        $this->actingAs($this->admin)->get(route('agent.university.file', [$theirs->ulid, 2, $lesson]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_ending_the_assignment_hides_that_companys_training(): void
    {
        $course = $this->course('ABC Plumbing guide', $this->abc);
        app(TrainingAssigner::class)->createRule($course, $this->admin, ['scope' => 'company', 'organization_id' => $this->abc->id]);
        $this->actingAs($this->agent)->get(route('agent.university', ['tab' => 'assigned']))->assertSee('ABC Plumbing guide');

        app(ChangeAssignment::class)->end(AgentAssignment::sole(), $this->admin, 'Moved to another account');
        $this->actingAs($this->agent->fresh())->get(route('agent.university', ['tab' => 'assigned']))->assertDontSee('ABC Plumbing guide');
        $this->get(route('agent.university.course', $course->ulid))->assertNotFound();
        $this->assertDatabaseHas('training_assignments', ['course_id' => $course->id, 'agent_user_id' => $this->agent->id]); // the record stays
    }

    public function test_supervisors_manage_only_their_companies_training_and_owners_none(): void
    {
        $supervisor = $this->user('agent', 'agent_supervisor', ['name' => 'Sam Supervisor']);
        app(AssignAgent::class)->handle($this->abc, $supervisor, $this->admin);
        $platform = $this->course('Platform standards');
        $theirs = $this->course('XYZ only', $this->xyz);
        $ours = $this->course('ABC only', $this->abc);

        $this->actingAs($supervisor);
        Livewire::test(Courses::class)->assertSee('ABC only')->assertSee('Platform standards')->assertDontSee('XYZ only')
            ->call('startCreate')->set('form.title', 'ABC phone etiquette')->set('form.for', $this->xyz->ulid)->call('create')->assertHasErrors('form.for')
            ->set('form.for', 'platform')->call('create')->assertHasErrors('form.for')
            ->set('form.for', $this->abc->ulid)->call('create')->assertHasNoErrors();
        $this->assertDatabaseHas('training_courses', ['title' => 'ABC phone etiquette', 'organization_id' => $this->abc->id]);

        // Editing: own company yes; platform content no (assigning only); other company not found.
        $this->get(route('agent.training.course', $ours->ulid))->assertOk();
        Livewire::test(CourseEditor::class, ['course' => $platform])->assertSet('section', 'assign')->call('addModule')->assertForbidden();
        $this->get(route('agent.training.course', $theirs->ulid))->assertNotFound();

        // Giving the platform course to the agents of their company, not to everyone.
        try {
            app(TrainingAssigner::class)->createRule($platform, $supervisor, ['scope' => 'everyone']);
            $this->fail('Supervisors can\'t give training to every agent');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        app(TrainingAssigner::class)->createRule($platform, $supervisor, ['scope' => 'company', 'organization_id' => $this->abc->id]);
        $this->assertDatabaseHas('training_assignments', ['course_id' => $platform->id, 'agent_user_id' => $this->agent->id]);

        // Progress: only agents serving their companies.
        $stranger = $this->user('agent', 'agent', ['name' => 'Xavier Elsewhere']);
        app(AssignAgent::class)->handle($this->xyz, $stranger, $this->admin);
        app(TrainingAssigner::class)->give($theirs, $stranger, $this->admin, ['is_required' => true]);
        $this->get(route('agent.training.progress'))->assertOk()->assertSee('Maria Agent')->assertDontSee('Xavier Elsewhere');

        // Business owners and agents never manage training.
        $owner = $this->abc->owner;
        $this->assertFalse($owner->hasPermissionIn('training.create', $this->abc));
        $this->actingAs($owner)->get(route('agent.training.courses'))->assertForbidden();
        $this->actingAs($owner)->get(route('agent.university'))->assertForbidden();
        $this->actingAs($this->agent)->get(route('agent.training.courses'))->assertForbidden();
    }

    public function test_starter_courses_are_ready_to_publish_and_installed_once(): void
    {
        $this->artisan('training:starter')->assertSuccessful();
        $this->artisan('training:starter')->assertSuccessful();

        $courses = TrainingCourse::query()->whereNull('organization_id')->get();
        $this->assertCount(2, $courses);
        foreach ($courses as $course) {
            $this->assertFalse($course->isPublished(), 'starters wait for review');
            $this->assertSame([], app(CoursePublisher::class)->problems($course));
        }
    }

    public function test_every_screen_renders_with_real_data(): void
    {
        $course = $this->course('ABC Plumbing guide', $this->abc, ['issues_certificate' => true, 'certificate_name' => 'ABC Certified', 'valid_for_months' => 1]);
        $platform = $this->course('Platform standards');
        app(TrainingAssigner::class)->createRule($course, $this->admin, ['scope' => 'company', 'organization_id' => $this->abc->id, 'due_days' => 3]);
        app(TrainingAssigner::class)->createRule($platform, $this->admin, ['scope' => 'everyone', 'due_days' => 5]);
        $this->take($course, $this->agent);
        $path = TrainingPath::create(['title' => 'Onboarding']);
        $path->courses()->attach([$platform->id => ['position' => 0], $course->id => ['position' => 1]]);

        $this->actingAs($this->agent);
        foreach (array_keys(Learning::TABS) as $tab) {
            $this->get(route('agent.university', ['tab' => $tab]))->assertOk();
        }
        $this->get(route('agent.university'))->assertSee('Onboarding');
        $this->get(route('agent.university', ['tab' => 'certificates']))->assertSee('ABC Certified');
        $this->get(route('agent.businesses.training', $this->abc->ulid))->assertOk()->assertSee('Training ready');
        $this->get(route('agent.companies'))->assertOk();

        $this->actingAs($this->admin);
        foreach (['agent.training.courses', 'agent.training.paths', 'agent.training.progress', 'agent.training.certificates'] as $route) {
            $this->get(route($route))->assertOk();
        }
        foreach (array_keys(CourseEditor::SECTIONS) as $section) {
            $this->get(route('agent.training.course', [$course->ulid, 'section' => $section]))->assertOk();
        }
        $this->get(route('agent.team.show', $this->agent->id))->assertOk()->assertSee('ABC Plumbing guide');
        Livewire::test(Paths::class)->call('edit', $path->id)->assertSee('Platform standards')->call('save')->assertHasNoErrors();
    }

    public function test_authors_build_a_course_in_the_editor(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(Courses::class)->call('startCreate')->set('form.title', 'Appointment scheduling')->set('form.for', 'platform')->call('create');
        $course = TrainingCourse::sole();

        $editor = Livewire::test(CourseEditor::class, ['course' => $course])
            ->set('newModule', 'Booking')->call('addModule');
        $module = TrainingModule::sole();
        $editor->set("newLesson.{$module->id}.title", 'Booking rules')->set("newLesson.{$module->id}.type", 'pdf')->call('addLesson', $module->id)
            ->set('lessonFile', UploadedFile::fake()->create('rules.pdf', 200, 'application/pdf'))->call('saveLesson')->assertHasNoErrors();
        $this->assertNotNull(TrainingLesson::sole()->file_path);
        Storage::disk('local')->assertExists(TrainingLesson::sole()->file_path);
        $editor->call('editLesson', TrainingLesson::sole()->id)->assertSee('rules.pdf')->assertSee('200 KB')->call('closeLesson'); // no intl needed

        $editor->set("newLesson.{$module->id}.title", 'Booking quiz')->set("newLesson.{$module->id}.type", 'quiz')->call('addLesson', $module->id)
            ->call('addQuestion')->set('questionForm.prompt', 'Earliest slot?')
            ->set('questionForm.options.0.label', 'Tomorrow 8 AM')->set('questionForm.options.1.label', 'Today 6 PM')->call('saveQuestion')->assertHasNoErrors()
            ->set('publishNote', 'First version')->call('publish')->assertHasNoErrors();

        $course->refresh();
        $this->assertSame(1, $course->current_version);
        $quiz = collect($course->version(1)->lessons())->firstWhere('type', 'quiz');
        $this->assertTrue($quiz['quiz']['questions'][0]['options'][0]['correct']);

        // A file a published version uses is kept when the draft lesson is deleted.
        $path = TrainingLesson::query()->where('type', 'pdf')->value('file_path');
        $editor->call('deleteLesson', TrainingLesson::query()->where('type', 'pdf')->value('id'));
        Storage::disk('local')->assertExists($path);
    }
}
