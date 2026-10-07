<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The business portal's search: this business's customers, calls, appointments and tasks only.
 */
class ClientSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_finds_this_businesss_records_only(): void
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $otherOwner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $other = app(ProvisionUserTenancy::class)->handle($otherOwner);

        $ana = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '(512) 555-0101']);
        Customer::create(['organization_id' => $other->id, 'first_name' => 'Ana', 'last_name' => 'Elsewhere']);
        Task::create(['organization_id' => $org->id, 'customer_id' => $ana->id, 'title' => 'Send quote']);
        $call = CallLog::withoutGlobalScopes()->create(['call_id' => CallLog::generateCallId(), 'organization_id' => $org->id, 'call_date' => now()->toDateString(),
            'call_time' => '10:00', 'caller_name' => 'Ana Lopez', 'caller_phone' => '5125550101', 'reason_for_call' => 'general-inquiry', 'call_outcome' => 'resolved-by-agent',
            'agent_name' => 'Agent', 'status' => 'new', 'user_id' => $owner->id]);

        $this->actingAs($owner)->get(route('app.dashboard'))->assertSee(route('app.search'));
        $this->get(route('app.search', ['q' => 'Ana']))->assertOk()
            ->assertSee('Ana Lopez')->assertDontSee('Elsewhere')->assertSee('Send quote')->assertSee($call->call_id);
        $this->get(route('app.search', ['q' => '0101']))->assertSee('Ana Lopez');
        $this->get(route('app.search', ['q' => 'a']))->assertSee('Type at least two characters');
    }
}
