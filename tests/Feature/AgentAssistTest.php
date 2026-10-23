<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Agent\Workspace;
use App\Models\AiRun;
use App\Models\BusinessHour;
use App\Models\KnowledgeItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * AI help for agents during calls (D88).
 */
class AgentAssistTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Organization $org;

    private ScriptedAiProvider $ai;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 15:00', 'UTC'));
        $this->ai = new ScriptedAiProvider;
        $this->app->instance(AiProvider::class, $this->ai);
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->org = app(ProvisionUserTenancy::class)->handle($owner);
        $this->org->update(['timezone' => 'UTC', 'name' => 'Rivera Plumbing']);
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $this->org->id, 'day_of_week' => $day, 'opens_at' => '08:00', 'closes_at' => '17:00']);
        }
        KnowledgeItem::create(['organization_id' => $this->org->id, 'type' => 'faq', 'title' => 'Call-out fee', 'content' => 'Call-out fee is $89, waived if we do the job.', 'visibility' => 'public', 'status' => 'published']);
        $this->org->assignAgent($this->agent);
        $this->actingAs($this->agent);
    }

    public function test_agents_get_an_answer_to_read_out(): void
    {
        $this->ai->reply(json_encode(['answer' => 'There\'s an $89 call-out fee, waived if we do the job.', 'confident' => true]));

        $page = Livewire::test(Workspace::class, ['organization' => $this->org])->set('knowledgeSearch', 'Do you charge to come out?')->call('askAi')->assertHasNoErrors()
            ->assertSee('$89 call-out fee, waived');
        $this->assertTrue($page->get('aiAnswer')['confident']);
        $request = $this->ai->requests[0];
        $this->assertStringContainsString('Call-out fee is $89', $request->instructions);
        $this->assertStringContainsString('You are NOT talking to a customer', $request->context);
        $this->assertSame('The caller asks: Do you charge to come out?', $request->messages[0]['content']);
        $this->assertSame(1, AiRun::query()->where('mode', 'assist')->count());

        // The daily cap.
        config(['ai.agent_assist_daily' => 1]);
        Livewire::test(Workspace::class, ['organization' => $this->org])->set('knowledgeSearch', 'Are you open Sunday?')->call('askAi')->assertHasErrors('assist');
    }

    public function test_the_form_is_filled_from_notes_without_overwriting(): void
    {
        $this->ai->reply(json_encode(['name' => 'Ana Lopez', 'phone' => '512 555 0147', 'email' => 'not-an-email', 'reason' => 'Leaking water heater',
            'outcome' => 'made_up_outcome', 'notes' => 'Ana\'s water heater is leaking in the garage; she wants someone today.']));

        $page = Livewire::test(Workspace::class, ['organization' => $this->org])->set('entry.phone', '5125550199')
            ->set('entry.notes', 'ana lopez water heater leaking garage wants today 512 555 0147')->call('fillFromNotes')->assertHasNoErrors();
        $this->assertSame('Ana Lopez', $page->get('entry.name'));
        $this->assertSame('5125550199', $page->get('entry.phone'), 'what the agent typed is kept');
        $this->assertSame('', $page->get('entry.email'));
        $this->assertSame('', $page->get('entry.outcome'), 'only outcomes the business has');
        $this->assertSame('Leaking water heater', $page->get('entry.reason'));
        $this->assertStringContainsString('leaking in the garage', $page->get('entry.notes'));
    }
}
