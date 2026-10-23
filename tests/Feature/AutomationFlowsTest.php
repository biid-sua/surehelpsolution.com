<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Client\Business\Automations;
use App\Models\Appointment;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Notifications\CustomerEmail;
use App\Services\Automation\Flow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Automations 2.0, step 2 (D81): flows of steps with waits and if / otherwise, the builder, test runs
 * and the customer's automation timeline.
 */
class AutomationFlowsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $org;

    private int $customers = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'UTC'));
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->org = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->org->forceFill(['name' => 'Rivera Plumbing', 'timezone' => 'UTC', 'status' => 'active'])->save();
        $this->org = $this->org->fresh();
        $this->actingAs($this->owner);
    }

    private function customer(array $attributes = []): Customer
    {
        $n = $this->customers++;

        return Customer::create($attributes + ['organization_id' => $this->org->id, 'first_name' => 'Ana', 'email' => "ana{$n}@example.test",
            'email_consent' => true, 'phone' => '(512) 555-'.str_pad((string) (300 + $n), 4, '0', STR_PAD_LEFT)]);
    }

    private function tag(string $name): Tag
    {
        return Tag::create(['organization_id' => $this->org->id, 'name' => $name]);
    }

    /** customer created → email → wait 1 day → if replied: tag "Replied", otherwise: tag "Quiet" and a task */
    private function flow(Tag $replied, Tag $quiet): Automation
    {
        return Automation::create(['organization_id' => $this->org->id, 'name' => 'Lead flow', 'trigger' => 'customer_created', 'delay_minutes' => 0,
            'action' => 'send_email', 'action_config' => [],
            'steps' => [
                ['type' => 'action', 'action' => 'send_email', 'config' => ['subject' => 'Hi {first_name}', 'body' => 'Thanks for getting in touch.', 'consent_only' => true]],
                ['type' => 'wait', 'minutes' => 1440],
                ['type' => 'branch', 'rules' => [['field' => 'replied', 'op' => 'yes']],
                    'then' => [['type' => 'action', 'action' => 'add_tag', 'config' => ['tag_id' => $replied->id]]],
                    'else' => [
                        ['type' => 'action', 'action' => 'add_tag', 'config' => ['tag_id' => $quiet->id]],
                        ['type' => 'action', 'action' => 'create_task', 'config' => ['title' => 'Call {first_name}', 'due_hours' => 4]],
                    ]],
            ]]);
    }

    private function emails(string $to, string $subject = ''): int
    {
        $n = 0;
        Notification::assertSentTo(new AnonymousNotifiable, CustomerEmail::class, function (CustomerEmail $mail, $channels, $notifiable) use ($to, $subject, &$n) {
            if (($notifiable->routes['mail'] ?? null) === $to && str_starts_with($mail->subject, $subject)) {
                $n++;
            }

            return true;
        });

        return $n;
    }

    public function test_a_flow_waits_then_takes_the_right_path(): void
    {
        $this->flow($replied = $this->tag('Replied'), $quiet = $this->tag('Quiet'));
        $talker = $this->customer();
        $silent = $this->customer();

        $this->artisan('automations:run')->assertSuccessful();
        $this->assertSame(1, $this->emails($talker->email));
        $this->assertSame(1, $this->emails($silent->email));
        $run = AutomationRun::withoutGlobalScopes()->where('customer_id', $talker->id)->firstOrFail();
        $this->assertSame('pending', $run->status);
        $this->assertSame([2], $run->cursor);
        $this->assertTrue($run->run_at->equalTo(now()->addDay()));

        // The talker writes back in the Inbox before the day is up.
        $this->travel(3)->hours();
        $conversation = Conversation::create(['organization_id' => $this->org->id, 'channel' => 'web_chat', 'channel_key' => 'web',
            'external_thread_id' => 'v1', 'customer_id' => $talker->id]);
        Message::create(['organization_id' => $this->org->id, 'conversation_id' => $conversation->id, 'direction' => Message::IN,
            'author_type' => 'customer', 'body' => 'Yes please!', 'status' => 'received']);

        $this->artisan('automations:run')->assertSuccessful();   // not yet: still waiting
        $this->assertSame('pending', $run->fresh()->status);

        $this->travel(1)->days();
        $this->artisan('automations:run')->assertSuccessful();

        $this->assertTrue($talker->tags()->whereKey($replied->id)->exists());
        $this->assertFalse($talker->tags()->whereKey($quiet->id)->exists());
        $this->assertTrue($silent->tags()->whereKey($quiet->id)->exists());
        $this->assertSame(1, Task::withoutGlobalScopes()->where('customer_id', $silent->id)->count());
        $this->assertSame(0, Task::withoutGlobalScopes()->where('customer_id', $talker->id)->count());

        $run->refresh();
        $this->assertSame('done', $run->status);
        $this->assertNull($run->cursor);
        $this->assertSame(['Email the customer', 'Wait 1 day', 'If a reply from them came in since the flow started', 'Add a tag to the customer'], array_column($run->log, 'step'));
        $this->assertSame(1, $this->emails($talker->email), 'The email went once, not again after the wait.');
    }

    public function test_switching_a_flow_off_stops_it_mid_wait(): void
    {
        $automation = $this->flow($this->tag('Replied'), $quiet = $this->tag('Quiet'));
        $customer = $this->customer();
        $this->artisan('automations:run')->assertSuccessful();
        $automation->update(['is_active' => false]);
        $this->travel(2)->days();
        $this->artisan('automations:run')->assertSuccessful();

        $this->assertSame('cancelled', AutomationRun::withoutGlobalScopes()->where('customer_id', $customer->id)->value('status'));
        $this->assertFalse($customer->tags()->whereKey($quiet->id)->exists());
    }

    public function test_a_skipped_step_doesnt_stop_the_flow(): void
    {
        $quiet = $this->tag('Quiet');
        Automation::create(['organization_id' => $this->org->id, 'name' => 'No consent', 'trigger' => 'customer_created', 'delay_minutes' => 0,
            'action' => 'send_email', 'action_config' => [], 'steps' => [
                ['type' => 'action', 'action' => 'send_email', 'config' => ['subject' => 'Hi', 'body' => 'Hello', 'consent_only' => true]],
                ['type' => 'action', 'action' => 'add_tag', 'config' => ['tag_id' => $quiet->id]],
            ]]);
        $customer = $this->customer(['email_consent' => false]);
        $this->artisan('automations:run')->assertSuccessful();

        $run = AutomationRun::withoutGlobalScopes()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('done', $run->status);
        $this->assertSame(['skipped', 'done'], array_column($run->log, 'status'));
        $this->assertTrue($customer->tags()->whereKey($quiet->id)->exists());
        Notification::assertNothingSent();
    }

    public function test_booked_again_skips_the_follow_up(): void
    {
        Livewire::test(Automations::class)->call('start', 'lead_nurture')->call('save')->assertHasNoErrors();
        $automation = Automation::query()->where('name', 'like', 'New lead%')->firstOrFail();
        $this->assertTrue($automation->isFlow());
        $this->assertSame(6, $automation->stepCount());

        $booker = $this->customer();
        $other = $this->customer();
        $this->artisan('automations:run')->assertSuccessful();
        $this->assertSame(2, Task::withoutGlobalScopes()->count());

        $this->travel(1)->days();
        $at = now()->addDays(3);
        Appointment::create(['organization_id' => $this->org->id, 'customer_id' => $booker->id, 'title' => 'Leak repair', 'timezone' => 'UTC',
            'starts_at' => $at, 'ends_at' => $at->addHour(), 'blocked_until' => $at->addHour()]);
        $this->travel(1)->days();
        $this->artisan('automations:run')->assertSuccessful();

        $this->assertSame('customer', $booker->fresh()->status->value);
        $this->assertSame(0, $this->emails($booker->email, 'Can we help'));
        $this->assertSame(1, $this->emails($other->email, 'Can we help'));
    }

    public function test_a_test_run_shows_the_steps_and_sends_nothing(): void
    {
        $customer = $this->customer(['first_name' => 'Bea']);
        Notification::fake();
        $quiet = $this->tag('Quiet');

        $page = Livewire::test(Automations::class)->call('start', 'lead_nurture')
            ->set('testCustomer', (string) $customer->id)->call('testRun')->assertHasNoErrors();
        $test = $page->get('test');
        $this->assertStringContainsString('Bea', $test['about']);
        $steps = array_column($test['lines'], 'step');
        $this->assertSame(['Start', 'Create a task for your team', 'Wait 2 days', 'If a new booking hasn\'t been made yet', 'Email the customer', 'Notify your team', 'End'], $steps);
        $this->assertStringStartsWith('Would create the task "Call Bea', $test['lines'][1]['text']);
        $this->assertStringStartsWith('Would email '.$customer->email, $test['lines'][4]['text']);

        $this->assertSame(0, Task::withoutGlobalScopes()->count());
        $this->assertSame(0, AutomationRun::withoutGlobalScopes()->count());
        $this->assertSame(0, Automation::query()->count(), 'A test run doesn\'t save.');
        Notification::assertNothingSent();
        $this->assertFalse($customer->tags()->whereKey($quiet->id)->exists());
    }

    public function test_a_test_run_says_when_theres_nothing_to_try(): void
    {
        $test = Livewire::test(Automations::class)->call('start', 'rebook')->call('testRun')->get('test');

        $this->assertStringContainsString('no appointment', $test['empty']);
    }

    public function test_the_builder_adds_moves_and_removes_steps(): void
    {
        $page = Livewire::test(Automations::class)->call('start', 'lead')
            ->call('addStep', '', 'wait')->call('addStep', '', 'branch')
            ->call('addStep', '1', 'action');   // not a list: ignored safely below
        $this->assertCount(3, $page->get('form.steps'));

        $page->call('addStep', '2.then', 'action')->set('form.steps.2.then.0.message', 'Still quiet: {first_name}')
            ->call('addStep', '2.then', 'branch');   // no branch inside a branch
        $this->assertCount(1, $page->get('form.steps.2.then'));

        $page->call('moveStep', '1', -1);
        $this->assertSame(['wait', 'action', 'branch'], array_column($page->get('form.steps'), 'type'));
        $page->call('moveStep', '0', 1)->call('save')->assertHasNoErrors();

        $saved = Automation::query()->firstOrFail();
        $this->assertSame(['action', 'wait', 'branch'], array_column($saved->steps, 'type'));
        $this->assertSame(1440, $saved->steps[1]['minutes']);
        $this->assertSame([['field' => 'replied', 'op' => 'no']], $saved->steps[2]['rules']);
        $this->assertSame('notify_team', $saved->action, 'The first action is kept on the automation too.');

        // Editing brings the flow back into the form.
        $page = Livewire::test(Automations::class)->call('edit', $saved->ulid)->assertSet('form.steps.1.amount', '1')->assertSet('form.steps.1.unit', 'days')
            ->assertSet('form.steps.2.then.0.message', 'Still quiet: {first_name}')
            ->call('removeStep', '2')->call('removeStep', '1')->call('save')->assertHasNoErrors();
        $this->assertNull($saved->fresh()->steps, 'Back to one action: stored as a plain automation.');
    }

    public function test_the_builder_checks_the_flow(): void
    {
        Livewire::test(Automations::class)->call('start', 'lead')->call('addStep', '', 'wait')->call('save')->assertHasErrors('form.steps');
        Livewire::test(Automations::class)->call('start', 'lead')->call('addStep', '', 'wait')->set('form.steps.1.amount', '0')
            ->call('addStep', '', 'action')->set('form.steps.2.message', 'Hi')->call('save')->assertHasErrors('form.steps.1.amount');
        Livewire::test(Automations::class)->call('start', 'lead')->call('addStep', '', 'wait')->set('form.steps.1.amount', '31')
            ->call('addStep', '', 'action')->set('form.steps.2.message', 'Hi')->call('save')->assertHasErrors('form.steps.1.amount');
        Livewire::test(Automations::class)->call('start', 'lead')->call('addStep', '', 'action')->set('form.steps.1.action', 'send_text')
            ->call('save')->assertHasErrors('form.steps.1.text');
        Livewire::test(Automations::class)->call('start', 'lead')->call('addStep', '', 'branch')->call('save')->assertHasErrors('form.steps.1.rules');
        // "They replied" belongs in an If step, not in "Only if".
        Livewire::test(Automations::class)->call('start', 'lead')->call('addRule')->set('form.rules.0.field', 'replied')
            ->call('save')->assertHasErrors('form.rules.0.value');

        $page = Livewire::test(Automations::class)->call('start', 'lead');
        for ($i = 0; $i < 20; $i++) {
            $page->call('addStep', '', 'wait');
        }
        $this->assertCount(Automation::MAX_STEPS, $page->get('form.steps'));
        $this->assertSame(0, Automation::query()->count());
    }

    public function test_the_customer_page_shows_their_automations(): void
    {
        $this->flow($this->tag('Replied'), $this->tag('Quiet'));
        $customer = $this->customer();
        $this->artisan('automations:run')->assertSuccessful();

        $this->get(route('app.customers.show', $customer->ulid))->assertOk()
            ->assertSee('Automations')->assertSee('Lead flow')->assertSee('Waiting')->assertSee('Emailed '.$customer->email);
    }

    public function test_flow_cursors_move_through_branches(): void
    {
        $steps = [
            ['type' => 'action'],
            ['type' => 'branch', 'then' => [['type' => 'action'], ['type' => 'wait']], 'else' => []],
            ['type' => 'action'],
        ];
        $this->assertSame([1, 'then', 1], Flow::next($steps, [1, 'then', 0]));
        $this->assertSame([2], Flow::next($steps, [1, 'then', 1]));
        $this->assertSame([2], Flow::enter($steps, [1], 'else'));
        $this->assertNull(Flow::next($steps, [2]));
        $this->assertSame(5, iterator_count(Flow::walk($steps)));
    }
}
