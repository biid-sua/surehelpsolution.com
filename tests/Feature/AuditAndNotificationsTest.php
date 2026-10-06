<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\NotificationEvent;
use App\Livewire\Admin\AuditLogs;
use App\Livewire\Admin\Organizations\Show;
use App\Livewire\Client\Settings\Notifications as NotificationSettings;
use App\Livewire\NotificationBell;
use App\Models\AuditLog;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CallActivity;
use App\Support\Audit\Audit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P1-5 — audit trail (spec §62) and notification foundation (spec §27, NTF-01/02).
 */
class AuditAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true, 'must_change_password' => false], $attributes));
    }

    /** @return array{0: User, 1: Organization} */
    private function business(array $attributes = []): array
    {
        $owner = $this->user('client', $attributes);

        return [$owner, app(ProvisionUserTenancy::class)->handle($owner)];
    }

    private function logCallAs(User $agent, User $client, array $overrides = []): void
    {
        $client->primaryOrganization()?->assignAgent($agent); // D40: agents only log calls for companies they serve
        $this->actingAs($agent)->postJson('/api/v1/agent/call-logs', array_merge([
            'client_id' => (string) $client->id,
            'call_date' => now()->toDateString(),
            'call_time' => '10:00',
            'caller_name' => 'Maria Lopez',
            'caller_phone' => '555-0100',
            'reason_for_call' => 'service-request',
            'call_outcome' => 'resolved-by-agent',
            'agent_name' => 'Agent',
            'status' => 'new',
        ], $overrides))->assertOk();
    }

    // Audit -----------------------------------------------------------------

    public function test_web_login_is_audited_and_session_is_regenerated(): void
    {
        $this->user('client', ['email' => 'owner@plumbing.test', 'password' => 'Secret123!']);

        $this->postJson(route('auth.login'), ['email' => 'owner@plumbing.test', 'password' => 'wrong'])->assertStatus(422);
        $failed = AuditLog::where('action', 'auth.login_failed')->sole();
        $this->assertSame('owner@plumbing.test', $failed->new_values['email']);
        $this->assertStringNotContainsString('wrong', json_encode($failed->toArray()), 'attempted password is never stored');

        $before = session()->getId();
        $this->postJson(route('auth.login'), ['email' => 'owner@plumbing.test', 'password' => 'Secret123!'])->assertOk();
        $this->assertNotSame($before, session()->getId(), 'session id changes on login');

        $login = AuditLog::where('action', 'auth.login')->sole();
        $this->assertSame('web', $login->new_values['channel']);
        $this->assertNotNull($login->ip_address);
    }

    public function test_api_login_is_audited(): void
    {
        $secret = $this->enableTwoFactor($this->user('agent', ['email' => 'agent@surehelp.test', 'password' => 'Secret123!']));

        $this->postJson('/api/v1/login', ['email' => 'agent@surehelp.test', 'password' => 'Secret123!', 'two_factor_code' => $this->twoFactorCode($secret)])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login']);
        $this->assertSame('api', AuditLog::where('action', 'auth.login')->sole()->new_values['channel']);
    }

    public function test_logging_a_call_is_audited_with_its_organization(): void
    {
        $agent = $this->user('agent');
        [$client, $org] = $this->business();

        $this->logCallAs($agent, $client);

        $entry = AuditLog::where('action', 'call.created')->sole();
        $this->assertSame($org->id, $entry->organization_id);
        $this->assertSame($agent->id, $entry->actor_id);
        $this->assertSame(CallLog::withoutGlobalScopes()->sole()->call_id, $entry->subject_label);
    }

    public function test_user_changes_are_audited_with_before_and_after_and_no_secrets(): void
    {
        $target = $this->user('admin', ['name' => 'Old Admin']);
        Sanctum::actingAs($admin = $this->user('admin'));

        $this->putJson("/api/v1/admin/users/{$target->id}", ['role' => 'agent', 'password' => 'NewSecret123'])->assertOk();

        $update = AuditLog::where('action', 'user.updated')->where('subject_id', $target->id)->latest('id')->first();
        $this->assertSame(['role' => 'admin'], $update->old_values);
        $this->assertSame(['role' => 'agent'], $update->new_values);
        $this->assertSame($admin->id, $update->actor_id);

        $password = AuditLog::where('action', 'user.password_changed')->where('subject_id', $target->id)->sole();
        $this->assertSame('administrator', $password->new_values['by']);
        $this->assertStringNotContainsString('NewSecret123', json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString($target->fresh()->password, json_encode(AuditLog::all()->toArray()), 'hashes are never stored');
    }

    public function test_redaction_covers_nested_secrets(): void
    {
        $redacted = app(Audit::class)->redact(['name' => 'x', 'Password' => 'p', 'meta' => ['api_key' => 'k', 'ok' => 1]]);

        $this->assertSame(['name' => 'x', 'Password' => '[redacted]', 'meta' => ['api_key' => '[redacted]', 'ok' => 1]], $redacted);
    }

    public function test_agent_assignment_changes_are_audited(): void
    {
        config(['tenancy.auto_assign_agents' => false]);
        $org = Organization::factory()->create();
        $agent = $this->user('agent', ['name' => 'Rita Agent']);

        $this->actingAs($this->user('admin'));
        Livewire::test(Show::class, ['organization' => $org])
            ->set('agentToAdd', (string) $agent->id)->call('assignAgent')
            ->call('unassignAgent', $agent->id);

        $this->assertSame('Rita Agent', AuditLog::where('action', 'agent.assigned')->sole()->new_values['agent']);
        $this->assertSame($org->id, AuditLog::where('action', 'agent.unassigned')->sole()->organization_id);
    }

    public function test_audit_page_is_for_platform_staff_with_permission_only(): void
    {
        app(Audit::class)->record('call.created', label: 'CL-TEST-0001');
        app(Audit::class)->record('auth.login', label: 'someone@example.test');

        $admin = $this->user('admin');
        $this->actingAs($admin)->get(route('admin.audit'))->assertOk()->assertSee('CL-TEST-0001');

        Livewire::test(AuditLogs::class)
            ->set('action', 'auth.login')
            ->assertSee('someone@example.test')
            ->assertDontSee('CL-TEST-0001');

        $support = $this->user('admin');
        $support->syncRoles(['support_agent']);
        $this->actingAs($support)->get(route('admin.audit'))->assertForbidden();

        [$owner] = $this->business();
        $this->actingAs($owner)->get(route('admin.audit'))->assertForbidden();
    }

    public function test_old_audit_entries_are_pruned_by_retention(): void
    {
        config(['audit.retention_days' => 30]);
        $old = app(Audit::class)->record('auth.login', label: 'old');
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        app(Audit::class)->record('auth.login', label: 'recent');

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

        $this->assertSame(['recent'], AuditLog::pluck('subject_label')->all());
    }

    // Notifications ---------------------------------------------------------

    public function test_logged_call_notifies_permitted_members_of_that_business_only(): void
    {
        Notification::fake();
        $agent = $this->user('agent');
        [$owner, $org] = $this->business();
        $staff = $this->user('client');
        $org->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $inactive = $this->user('client', ['is_active' => false]);
        $org->members()->attach($inactive->id, ['role' => 'manager', 'status' => 'active']);
        [$otherOwner] = $this->business();

        $this->logCallAs($agent, $owner);

        Notification::assertSentTo([$owner, $staff], CallActivity::class,
            fn (CallActivity $n, array $channels) => $n->event === NotificationEvent::CallLogged && $channels === ['database']);
        Notification::assertNotSentTo([$inactive, $otherOwner, $agent], CallActivity::class);
    }

    public function test_outcome_decides_the_event_and_default_channels(): void
    {
        Notification::fake();
        $agent = $this->user('agent');
        [$owner] = $this->business();

        $this->logCallAs($agent, $owner, ['call_outcome' => 'call-dropped']);
        Notification::assertSentTo($owner, CallActivity::class,
            fn (CallActivity $n, array $channels) => $n->event === NotificationEvent::CallMissed && $channels === ['database', 'mail']);

        $this->logCallAs($agent, $owner, ['call_outcome' => 'callback-requested']);
        Notification::assertSentTo($owner, CallActivity::class,
            fn (CallActivity $n) => $n->event === NotificationEvent::FollowUpCreated);
    }

    public function test_preferences_change_channels_and_disabled_channels_never_apply(): void
    {
        [$owner] = $this->business();

        $this->actingAs($owner);
        Livewire::test(NotificationSettings::class)
            ->assertSet('preferences.CallMissed.mail', true)
            ->set('preferences.CallMissed.mail', false)
            ->set('preferences.CallLogged.mail', true)
            ->call('save')
            ->assertDispatched('toast');

        $owner->refresh()->load('notificationPreferences');
        $this->assertSame(['database'], $owner->notificationChannelsFor(NotificationEvent::CallMissed));
        $this->assertSame(['database', 'mail'], $owner->notificationChannelsFor(NotificationEvent::CallLogged));

        // SMS isn't connected yet, so it's ignored even if stored.
        $owner->notificationPreferences()->where('event', 'call.logged')->update(['channels' => json_encode(['sms', 'database'])]);
        $this->assertSame(['database'], $owner->fresh()->notificationChannelsFor(NotificationEvent::CallLogged));
    }

    public function test_in_app_notification_and_mail_are_delivered(): void
    {
        $agent = $this->user('agent');
        [$owner] = $this->business(['email' => 'owner@plumbing.test']);

        $this->logCallAs($agent, $owner, ['call_outcome' => 'call-dropped', 'notes' => 'Leaking heater']);

        $notification = $owner->notifications()->sole();
        $this->assertSame('call.missed', $notification->type);
        $this->assertSame('Missed call from Maria Lopez', $notification->data['title']);
        $this->assertStringStartsWith('/app/calls/', $notification->data['url']);

        $mail = (new CallActivity(CallLog::withoutGlobalScopes()->sole(), NotificationEvent::CallMissed))->toMail($owner);
        $this->assertStringContainsString('Missed call from Maria Lopez', $mail->subject);
        $this->assertStringContainsString('Leaking heater', implode(' ', $mail->introLines));
    }

    public function test_bell_shows_only_own_notifications_and_marks_them_read(): void
    {
        $agent = $this->user('agent');
        [$owner] = $this->business();
        [$otherOwner] = $this->business();
        $this->logCallAs($agent, $owner);
        $this->logCallAs($agent, $otherOwner, ['caller_name' => 'Someone Else']);

        $mine = $owner->notifications()->sole();
        $theirs = $otherOwner->notifications()->sole();

        $this->actingAs($owner);
        $bell = Livewire::test(NotificationBell::class)
            ->assertViewHas('unread', 1)
            ->assertSee('New call from Maria Lopez')
            ->assertDontSee('Someone Else');

        $bell->call('open', $mine->id)->assertRedirect($mine->data['url']);
        $this->assertNotNull($mine->fresh()->read_at);

        $this->expectException(ModelNotFoundException::class);
        $bell->call('open', $theirs->id);
    }

    public function test_bell_mark_all_read(): void
    {
        $agent = $this->user('agent');
        [$owner] = $this->business();
        $this->logCallAs($agent, $owner);
        $this->logCallAs($agent, $owner);

        $this->actingAs($owner);
        Livewire::test(NotificationBell::class)->assertViewHas('unread', 2)->call('markAllRead')->assertViewHas('unread', 0);
    }

    // Scheduler -------------------------------------------------------------

    public function test_scheduler_runs_queue_worker_and_retention(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode("\n");

        $this->assertStringContainsString('queue:work', $commands);
        $this->assertStringContainsString('--stop-when-empty', $commands);
        $this->assertStringContainsString('model:prune', $commands);
        $this->assertStringContainsString('queue:prune-failed', $commands);
    }
}
