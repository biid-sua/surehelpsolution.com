<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\OrganizationStatus;
use App\Livewire\Admin\Organizations\Show;
use App\Models\AuditLog;
use App\Models\ChatWidget;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\ServiceStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SureHelp staff take a business live, pause, resume and cancel its service (ADM-02, ONB-11, D45).
 */
class ServiceStatusTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Organization} */
    private function business(OrganizationStatus $status): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['name' => 'Rivera Plumbing', 'status' => $status, 'setup_completed_at' => now()])->save();

        return [$owner, $org->fresh()];
    }

    private function staff(string $role = 'super_admin'): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        if ($role !== 'super_admin') {
            $user->syncRoles([$role]);
        }

        return $user;
    }

    public function test_staff_take_a_business_live_pause_it_with_a_reason_and_resume_it(): void
    {
        Notification::fake();
        [$owner, $org] = $this->business(OrganizationStatus::Onboarding);
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $this->enableTwoFactor($agent);
        $org->assignAgent($agent);
        $widget = ChatWidget::for($org);

        $this->actingAs($this->staff('operations_manager'));
        $page = Livewire::test(Show::class, ['organization' => $org])->assertSee('Go live')->assertDontSee('Resume');
        $page->call('setActive');
        $this->assertSame(OrganizationStatus::Active, $org->fresh()->status);
        Notification::assertSentTo($owner, ServiceStatusChanged::class, fn ($n) => str_contains($n->toArray($owner)['title'], 'is live'));

        $page->call('setPaused')->assertHasErrors('reason');
        $page->set('statusReason', 'Invoice INV-2026-0042 is 30 days overdue')->call('setPaused')->assertHasNoErrors();
        $org->refresh();
        $this->assertSame(OrganizationStatus::Paused, $org->status);
        $this->assertSame('Invoice INV-2026-0042 is 30 days overdue', $org->status_reason);
        $this->assertTrue(AuditLog::where('action', 'organization.status_changed')->exists());

        // Paused: agents don't see it, the website tools are hidden, the owner sees why.
        $this->actingAs($agent)->get(route('agent.home'))->assertDontSee('Rivera Plumbing');
        $this->call('GET', '/api/chat/'.$widget->public_key.'/config', [], [], [], ['HTTP_ORIGIN' => 'https://rivera.test'])->assertNotFound();
        $this->actingAs($owner)->get(route('app.dashboard'))->assertSee('Your SureHelp service is paused')->assertSee('30 days overdue');

        $this->actingAs($this->staff());
        Livewire::test(Show::class, ['organization' => $org])->assertSee('Resume')->call('setActive');
        $this->assertSame(OrganizationStatus::Active, $org->fresh()->status);
        $this->call('GET', '/api/chat/'.$widget->public_key.'/config', [], [], [], ['HTTP_ORIGIN' => 'https://rivera.test'])->assertOk();
    }

    public function test_cancelling_needs_a_reason_and_support_staff_cannot_change_status(): void
    {
        Notification::fake();
        [, $org] = $this->business(OrganizationStatus::Active);

        $this->actingAs($this->staff('support_agent'));
        Livewire::test(Show::class, ['organization' => $org])->assertDontSee('Cancel service')->call('setPaused')->assertForbidden();

        $this->actingAs($this->staff());
        Livewire::test(Show::class, ['organization' => $org])->set('statusReason', 'Owner asked to stop')->call('setCancelled')->assertHasNoErrors()
            ->assertSee('Reactivate');
        $this->assertSame(OrganizationStatus::Cancelled, $org->fresh()->status);

        // An account the owner closed can't come back.
        $org->forceFill(['closed_at' => now()])->save();
        Livewire::test(Show::class, ['organization' => $org])->call('setActive')->assertHasErrors('status');
    }
}
