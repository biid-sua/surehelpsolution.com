<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\SupportTicketStatus;
use App\Livewire\Admin\Support\Index as AdminSupport;
use App\Livewire\Client\Support\Index as ClientSupport;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Notifications\SupportTicketActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Support requests (spec §78, CLI-09, D49): businesses open and reply, SureHelp staff answer,
 * each side is told, attachments stay private, and businesses only see their own.
 */
class SupportTicketsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $this->admin->syncRoles(['super_admin']);
    }

    /** @return array{0: User, 1: Organization} */
    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);

        return [$owner, app(ProvisionUserTenancy::class)->handle($owner)];
    }

    public function test_a_business_opens_a_request_staff_reply_and_the_business_replies_back(): void
    {
        [$owner, $org] = $this->business();

        $this->actingAs($owner)->get(route('app.support', ['new' => 'script']))->assertOk()->assertSee('Script change request');
        Livewire::test(ClientSupport::class)->call('startNew', 'script_change')
            ->set('form.body', '')->call('create')->assertHasErrors('form.body')
            ->set('form.body', 'Please greet callers with "Rivera Plumbing, how can we help?"')
            ->set('file', UploadedFile::fake()->create('greeting.pdf', 100, 'application/pdf'))
            ->call('create')->assertHasNoErrors();

        $ticket = SupportTicket::withoutGlobalScopes()->sole();
        $this->assertSame('script_change', $ticket->category);
        $this->assertSame(SupportTicketStatus::Open, $ticket->status);
        Notification::assertSentTo($this->admin, SupportTicketActivity::class);
        $message = $ticket->messages()->first();
        Storage::disk('local')->assertExists($message->attachment_path);
        $this->get(route('app.support.attachment', $message->id))->assertOk();

        $this->actingAs($this->admin);
        Livewire::test(AdminSupport::class)->assertSee($ticket->subject)
            ->call('open', $ticket->ulid)->call('assignToMe')
            ->set('reply', 'Done: agents now use that greeting.')->call('send')->assertHasNoErrors();
        $ticket->refresh();
        $this->assertSame(SupportTicketStatus::WaitingForCustomer, $ticket->status);
        $this->assertSame($this->admin->id, $ticket->assigned_to_user_id);
        Notification::assertSentTo($owner, SupportTicketActivity::class, fn (SupportTicketActivity $n) => ! $n->staff);
        $this->get(route('admin.support.attachment', $message->id))->assertOk();

        $this->actingAs($owner);
        Livewire::test(ClientSupport::class, ['selected' => $ticket->ulid])->assertSee('Done: agents now use that greeting.')
            ->set('reply', 'Thanks! One more thing...')->call('send')->assertHasNoErrors();
        $this->assertSame(SupportTicketStatus::InProgress, $ticket->fresh()->status, 'assigned, so it stays in progress');

        Livewire::test(ClientSupport::class, ['selected' => $ticket->ulid])->call('close');
        $this->assertSame(SupportTicketStatus::Closed, $ticket->fresh()->status);
        Livewire::test(ClientSupport::class, ['selected' => $ticket->ulid])->set('reply', 'Again')->call('send')->assertHasErrors('reply');
    }

    public function test_businesses_only_see_their_own_requests_and_files(): void
    {
        [$owner] = $this->business();
        [$other, $otherOrg] = $this->business();
        $this->actingAs($other);
        Livewire::test(ClientSupport::class)->call('startNew')->set('form.subject', 'Billing question')->set('form.body', 'Hi')
            ->set('file', UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'))->call('create');
        $ticket = SupportTicket::withoutGlobalScopes()->sole();

        $this->actingAs($owner)->get(route('app.support', ['ticket' => $ticket->ulid]))->assertOk()->assertDontSee('Billing question');
        $this->get(route('app.support.attachment', SupportTicketMessage::withoutGlobalScopes()->where('support_ticket_id', $ticket->id)->value('id')))->assertNotFound();
        $this->get(route('admin.support'))->assertForbidden();
    }

    public function test_staff_without_support_permission_and_agents_cant_answer(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);
        $agent->syncRoles(['agent']);
        $this->actingAs($agent)->get(route('admin.support'))->assertForbidden();

        $support = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $support->syncRoles(['support_agent']);
        $this->actingAs($support)->get(route('admin.support'))->assertOk();
    }
}
