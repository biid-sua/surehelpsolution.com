<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Admin\Organizations\Index;
use App\Models\CallLog;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Services\Admin\ClientHealth;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Client health (D86): warning signs lower a business's score, and the admin console shows who to call.
 */
class ClientHealthTest extends TestCase
{
    use RefreshDatabase;

    private int $calls = 0;

    private ?User $agent = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'UTC'));
    }

    private function business(string $name, bool $signsIn = true): Organization
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'last_login_at' => $signsIn ? now()->subDays(2) : null]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['name' => $name, 'status' => 'active', 'setup_completed_at' => now()->subDays(100), 'created_at' => now()->subDays(120)])->save();

        return $org->fresh();
    }

    private function callsOn(Organization $org, int $count, int $daysAgo): void
    {
        $this->agent ??= User::factory()->create(['role' => 'agent', 'is_active' => true]);
        for ($i = 0; $i < $count; $i++) {
            $at = now()->subDays($daysAgo);
            CallLog::create(['call_id' => 'C'.(++$this->calls), 'organization_id' => $org->id, 'caller_name' => 'X', 'caller_phone' => '5125550100', 'reason_for_call' => 'Leak',
                'call_outcome' => 'message_taken', 'agent_name' => 'A', 'status' => 'completed', 'call_date' => $at, 'call_time' => '10:00', 'user_id' => $this->agent->id])
                ->forceFill(['created_at' => $at])->save();
        }
    }

    public function test_warning_signs_lower_the_score_with_reasons(): void
    {
        $fine = $this->business('Fine Co');
        $this->callsOn($fine, 20, 10);
        $this->callsOn($fine, 20, 40);

        $slipping = $this->business('Slipping Co', signsIn: false);
        $this->callsOn($slipping, 3, 10);
        $this->callsOn($slipping, 20, 40);
        Invoice::create(['number' => 'INV-1', 'organization_id' => $slipping->id, 'status' => 'open', 'currency' => 'USD', 'subtotal_cents' => 40000, 'total_cents' => 40000,
            'amount_paid_cents' => 0, 'issued_at' => now()->subDays(40), 'due_at' => now()->subDays(20)]);

        $health = app(ClientHealth::class)->all();
        $this->assertSame(['score' => 100, 'band' => 'healthy', 'reasons' => []], $health[$fine->id]);
        $this->assertSame(25, $health[$slipping->id]['score']);
        $this->assertSame('risk', $health[$slipping->id]['band']);
        $this->assertSame(['Calls down 85% on the month before', '1 overdue invoice (20 days late)', 'Nobody has signed in yet'], $health[$slipping->id]['reasons']);

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($admin);
        Livewire::test(Index::class)->set('health', 'risk')->assertSee('Slipping Co')->assertDontSee('Fine Co')->assertSee('25 · At risk');
        $this->get(route('admin.home'))->assertOk()->assertSee('Clients to check')->assertSee('Calls down 85% on the month before');
        $this->get(route('admin.organizations.show', $slipping))->assertOk()->assertSee('Client health 25 / 100');
    }
}
