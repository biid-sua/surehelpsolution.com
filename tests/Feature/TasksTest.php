<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Actions\Tasks\CreateCallbackTask;
use App\Enums\NotificationEvent;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\TimelineEventType;
use App\Livewire\Client\Dashboard;
use App\Livewire\Client\Tasks\Index;
use App\Models\BusinessHour;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskActivity;
use App\Services\Tasks\OverdueTaskSweep;
use App\Services\Tasks\TaskBackfill;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-4b — tasks and follow-ups (spec §24, CLI-04).
 */
class TasksTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Organization} */
    private function business(string $timezone = 'America/Chicago'): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization = app(ProvisionUserTenancy::class)->handle($owner);
        $organization->update(['timezone' => $timezone]);

        return [$owner, $organization->fresh()];
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization->members()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function logCall(User $client, string $outcome = 'callback-requested'): TestResponse
    {
        return $this->actingAs($this->agent)->postJson(route('admin.call-logs.store'), [
            'client_id' => (string) $client->id,
            'call_date' => now()->toDateString(), 'call_time' => '10:00',
            'caller_name' => 'Maria Lopez', 'caller_phone' => '(512) 555-0147',
            'reason_for_call' => 'service-request', 'call_outcome' => $outcome,
            'agent_name' => 'Agent', 'status' => 'new', 'notes' => 'Leaking water heater',
        ]);
    }

    private function weekdayHours(Organization $organization): void
    {
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $organization->id, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        }
    }

    public function test_a_callback_call_creates_one_task_linked_to_the_customer(): void
    {
        [$owner, $org] = $this->business();
        $this->logCall($owner)->assertOk();
        $this->logCall($owner, 'resolved-by-agent')->assertOk();

        $task = Task::withoutGlobalScopes()->sole();
        $call = CallLog::withoutGlobalScopes()->where('call_outcome', 'callback-requested')->sole();

        $this->assertSame(TaskType::Callback, $task->type);
        $this->assertSame($org->id, $task->organization_id);
        $this->assertSame($call->id, $task->call_log_id);
        $this->assertSame($call->customer_id, $task->customer_id);
        $this->assertStringContainsString('Call back Maria Lopez', $task->title);
        $this->assertStringContainsString('Leaking water heater', (string) $task->description);
        $this->assertDatabaseHas('customer_timeline_events', ['customer_id' => $call->customer_id, 'type' => TimelineEventType::TaskCreated->value]);

        // Shown where the business looks: the customer and the call.
        $this->actingAs($owner)->get(route('app.customers.show', $call->customer))->assertOk()->assertSee($task->title)->assertSee('Add task');
        $this->actingAs($owner)->get(route('app.calls.show', $call->call_id))->assertOk()->assertSee($task->title);
        $this->actingAs($owner)->get(route('app.tasks.index', ['task' => $task->ulid]))->assertOk()->assertSee('Edit task');

        // Idempotent per call.
        $this->assertSame($task->id, app(CreateCallbackTask::class)->handle($call, $org)->id);
        $this->assertSame(1, Task::withoutGlobalScopes()->count());
    }

    public function test_callback_due_time_follows_business_hours(): void
    {
        [$owner, $org] = $this->business('America/Chicago');
        $this->weekdayHours($org);

        // Friday 8 PM Chicago: closed until Monday 9 AM, so due Monday 10 AM.
        Carbon::setTestNow(Carbon::parse('2026-10-09 20:00', 'America/Chicago'));
        $this->logCall($owner)->assertOk();
        $this->assertSame('2026-10-12 10:00', Task::withoutGlobalScopes()->sole()->due_at->setTimezone('America/Chicago')->format('Y-m-d H:i'));

        // Open: due within the hour.
        Carbon::setTestNow(Carbon::parse('2026-10-12 11:15', 'America/Chicago'));
        $this->logCall($owner)->assertOk();
        $this->assertSame('2026-10-12 12:15', Task::withoutGlobalScopes()->latest('id')->first()->due_at->setTimezone('America/Chicago')->format('Y-m-d H:i'));
    }

    public function test_follow_up_kpi_and_view_follow_open_tasks(): void
    {
        [$owner, $org] = $this->business();
        $this->logCall($owner)->assertOk();

        $this->actingAs($owner);
        $this->assertSame(1, Livewire::test(Dashboard::class)->viewData('kpis')['follow_ups']['value']);

        $task = Task::withoutGlobalScopes()->sole();
        Livewire::test(Index::class)->assertSee($task->title)->call('setStatus', $task->ulid, 'completed');

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame($owner->id, $task->completed_by_user_id);
        $this->assertDatabaseHas('customer_timeline_events', ['customer_id' => $task->customer_id, 'type' => TimelineEventType::TaskCompleted->value]);
        $this->assertSame(0, Livewire::test(Dashboard::class)->viewData('kpis')['follow_ups']['value']);

        Livewire::test(Index::class)->call('setStatus', $task->ulid, 'open');
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_owner_creates_and_assigns_a_task_in_business_time(): void
    {
        [$owner, $org] = $this->business('America/New_York');
        $staff = $this->member($org, 'staff');

        $this->actingAs($owner)->get(route('app.tasks.index'))->assertOk()->assertSee('all caught up');

        Livewire::test(Index::class)
            ->call('create')
            ->set('form.title', 'Order replacement valve')
            ->set('form.priority', 'high')
            ->set('form.due_date', '2026-10-20')
            ->set('form.due_time', '14:30')
            ->set('form.assigned_to_user_id', (string) $staff->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', false);

        $task = Task::withoutGlobalScopes()->sole();
        $this->assertSame('2026-10-20 18:30', $task->due_at->utc()->format('Y-m-d H:i'), '14:30 in New York is 18:30 UTC');
        $this->assertSame($staff->id, $task->assigned_to_user_id);
        Notification::assertSentTo($staff, TaskActivity::class, fn (TaskActivity $n) => $n->event === NotificationEvent::TaskAssigned);
        $this->assertDatabaseHas('audit_logs', ['action' => 'task.created', 'organization_id' => $org->id]);

        // Staff can see and work it.
        $this->actingAs($staff);
        Livewire::test(Index::class)->set('view', 'mine')->assertSee('Order replacement valve')
            ->call('setStatus', $task->ulid, 'in_progress');
        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
    }

    public function test_tasks_cannot_be_given_to_people_outside_the_business(): void
    {
        [$owner] = $this->business();
        [$outsider] = $this->business();

        $this->actingAs($owner);
        Livewire::test(Index::class)
            ->call('create')
            ->set('form.title', 'Sneaky')
            ->set('form.assigned_to_user_id', (string) $outsider->id)
            ->call('save')
            ->assertHasErrors('form.assigned_to_user_id');

        $this->assertSame(0, Task::withoutGlobalScopes()->count());
    }

    public function test_another_businesss_tasks_are_invisible(): void
    {
        [$owner] = $this->business();
        [$other] = $this->business();
        $this->logCall($other)->assertOk();
        $theirs = Task::withoutGlobalScopes()->sole();

        $this->actingAs($owner);
        Livewire::test(Index::class)->assertDontSee($theirs->title);
        foreach ([['edit', $theirs->ulid], ['setStatus', $theirs->ulid, 'completed']] as $call) {
            try {
                Livewire::test(Index::class)->call(...$call);
                $this->fail($call[0].' reached another business\'s task');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertTrue($theirs->fresh()->status->isOpen());
    }

    public function test_overdue_tasks_notify_once(): void
    {
        [$owner, $org] = $this->business();
        $staff = $this->member($org, 'staff');
        $this->logCall($owner)->assertOk();
        $task = Task::withoutGlobalScopes()->sole();

        $sweep = app(OverdueTaskSweep::class);
        $this->assertSame(0, $sweep->run(), 'not due yet');

        Carbon::setTestNow(now()->addDays(3));
        $this->assertSame(1, $sweep->run());
        $this->assertSame(0, $sweep->run(), 'only once');
        Notification::assertSentTo([$owner, $staff], TaskActivity::class, fn (TaskActivity $n) => $n->event === NotificationEvent::FollowUpOverdue);

        // A new due date earns a new reminder, sent only to the assignee.
        $this->actingAs($owner);
        Livewire::test(Index::class)->call('edit', $task->ulid)
            ->set('form.due_date', now()->subDay()->toDateString())
            ->set('form.assigned_to_user_id', (string) $staff->id)
            ->call('save')->assertHasNoErrors();
        $this->assertNull($task->fresh()->overdue_notified_at);
        Notification::fake();
        $this->assertSame(1, $sweep->run());
        Notification::assertSentTo($staff, TaskActivity::class);
        Notification::assertNotSentTo($owner, TaskActivity::class);
    }

    public function test_backfill_turns_waiting_callbacks_into_tasks_without_alert_bursts(): void
    {
        [, $org] = $this->business();
        $make = fn (array $attributes) => CallLog::withoutGlobalScopes()->create(array_merge([
            'call_id' => CallLog::generateCallId(), 'organization_id' => $org->id,
            'call_date' => now()->toDateString(), 'call_time' => '10:00', 'caller_name' => 'Old caller',
            'reason_for_call' => 'general-inquiry', 'call_outcome' => 'callback-requested',
            'agent_name' => 'Agent', 'status' => 'new', 'user_id' => $this->agent->id,
        ], $attributes));

        $old = $make([]);
        $old->forceFill(['created_at' => now()->subWeek()])->saveQuietly();
        $make(['status' => 'completed']);
        $make(['call_outcome' => 'resolved-by-agent']);

        $backfill = app(TaskBackfill::class);
        $this->assertSame(['tasks_created' => 1, 'already_overdue' => 1], $backfill->run(dryRun: true));
        $this->assertSame(0, Task::withoutGlobalScopes()->count());

        $backfill->run();
        $backfill->run();
        $task = Task::withoutGlobalScopes()->sole();
        $this->assertSame('backfill', $task->source);
        $this->assertNotNull($task->overdue_notified_at);
        $this->assertSame(0, app(OverdueTaskSweep::class)->run());
    }

    public function test_agent_changing_an_outcome_to_callback_creates_the_task(): void
    {
        [$owner] = $this->business();
        $this->logCall($owner, 'resolved-by-agent')->assertOk();
        $call = CallLog::withoutGlobalScopes()->sole();

        Sanctum::actingAs($this->agent);
        $this->putJson("/api/v1/agent/call-logs/{$call->id}", ['call_outcome' => 'followup-scheduled'])->assertOk();
        $this->putJson("/api/v1/agent/call-logs/{$call->id}", ['call_outcome' => 'callback-requested'])->assertOk();

        $this->assertSame(1, Task::withoutGlobalScopes()->where('call_log_id', $call->id)->count());
    }

    public function test_tasks_api(): void
    {
        [$owner, $org] = $this->business();
        [$other] = $this->business();
        $this->logCall($owner)->assertOk();
        $this->logCall($other)->assertOk();
        $customer = CallLog::withoutGlobalScopes()->where('organization_id', $org->id)->sole()->customer;

        Sanctum::actingAs($owner);
        $list = $this->getJson('/api/v1/client/tasks')->assertOk()->assertJsonPath('success', true)->json('data.tasks');
        $this->assertCount(1, $list);
        $this->assertSame('callback', $list[0]['type']);
        $this->assertSame($customer->ulid, $list[0]['customer']['id']);

        $created = $this->postJson('/api/v1/client/tasks', [
            'title' => 'Send quote', 'priority' => 'urgent', 'customer_id' => $customer->ulid, 'due_at' => '2026-10-20T15:00:00Z',
        ])->assertCreated()->assertJsonPath('data.task.priority', 'urgent')->json('data.task.id');

        $this->patchJson("/api/v1/client/tasks/{$created}", ['status' => 'completed', 'title' => 'Send quote (done)'])
            ->assertOk()->assertJsonPath('data.task.status', 'completed')->assertJsonPath('data.task.title', 'Send quote (done)');
        $this->getJson('/api/v1/client/tasks?status=done')->assertOk()->assertJsonCount(1, 'data.tasks');

        $theirs = Task::withoutGlobalScopes()->where('organization_id', '!=', $org->id)->sole();
        $this->getJson("/api/v1/client/tasks/{$theirs->ulid}")->assertNotFound();
        $this->patchJson("/api/v1/client/tasks/{$theirs->ulid}", ['status' => 'completed'])->assertNotFound();
        $this->postJson('/api/v1/client/tasks', ['title' => 'x', 'customer_id' => 'nope'])->assertStatus(422);
    }
}
