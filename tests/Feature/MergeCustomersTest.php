<?php

namespace Tests\Feature;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Customers\MergeCustomers;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\CustomerStatus;
use App\Livewire\Client\Customers\Duplicates;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use App\Services\Customers\DuplicateCustomers;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Merging duplicate customers (spec CRM-04).
 */
class MergeCustomersTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Organization} */
    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['setup_completed_at' => now()])->save();

        return [$owner, $org->fresh()];
    }

    public function test_merging_moves_history_fills_gaps_and_archives_the_duplicate(): void
    {
        Bus::fake();
        [$owner, $org] = $this->business();
        $keep = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '5125550101', 'status' => CustomerStatus::Lead]);
        $dup = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '5125550199', 'email' => 'ana@example.test',
            'city' => 'Austin', 'status' => CustomerStatus::Customer, 'notes' => 'Prefers mornings', 'email_consent' => true, 'email_consent_at' => now()]);
        $vip = Tag::create(['organization_id' => $org->id, 'name' => 'VIP']);
        $dup->tags()->attach($vip);
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        CallLog::create(['user_id' => $agent->id, 'organization_id' => $org->id, 'customer_id' => $dup->id, 'call_id' => 'CL-1', 'call_date' => '2026-10-01', 'call_time' => '09:00',
            'reason_for_call' => 'service-request', 'call_outcome' => 'other', 'agent_name' => 'A', 'status' => 'new']);
        $appt = app(BookAppointment::class)->handle($org, ['starts_at' => now()->addDay(), 'title' => 'Visit', 'customer_id' => $dup->id, 'status' => 'pending']);

        $kept = app(MergeCustomers::class)->handle($keep, $dup, $owner);

        $this->assertSame('ana@example.test', $kept->email, 'empty fields filled from the duplicate');
        $this->assertSame('Austin', $kept->city);
        $this->assertSame('+15125550101', $kept->phone_e164, 'the kept record\'s own details win');
        $this->assertSame(CustomerStatus::Customer, $kept->status);
        $this->assertTrue($kept->email_consent);
        $this->assertStringContainsString('Prefers mornings', $kept->notes);
        $this->assertTrue($kept->tags->contains($vip));
        $this->assertSame($kept->id, CallLog::where('call_id', 'CL-1')->sole()->customer_id);
        $this->assertSame($kept->id, $appt->fresh()->customer_id);

        $gone = Customer::withTrashed()->find($dup->id);
        $this->assertTrue($gone->trashed());
        $this->assertSame($kept->id, $gone->merged_into_id);
        $this->assertNull($gone->phone_e164, 'its phone is freed (phones are unique per business)');
        $this->assertSame('Merged with Ana Lopez', $kept->timeline()->first()->title);

        // The duplicate's phone can now go on another record.
        Customer::create(['organization_id' => $org->id, 'first_name' => 'Ben', 'phone' => '5125550199']);
        $this->expectException(ValidationException::class);
        app(MergeCustomers::class)->handle($kept, $kept, $owner);
    }

    public function test_owners_review_suggestions_and_choose_which_record_stays(): void
    {
        [$owner, $org] = $this->business();
        $a = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '5125550101']);
        $b = Customer::create(['organization_id' => $org->id, 'first_name' => 'ana', 'last_name' => 'lopez', 'email' => 'ana@example.test']);
        Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'phone' => '5125550103']);   // first name only: not a match
        Customer::create(['organization_id' => $org->id, 'first_name' => 'Zed', 'email' => 'ANA@example.test']);

        $this->assertSame(2, app(DuplicateCustomers::class)->count($org));
        $this->assertCount(2, app(DuplicateCustomers::class)->for($org));

        $this->actingAs($owner)->get(route('app.customers.index'))->assertSee('2 possible duplicates');
        $this->get(route('app.customers.duplicates'))->assertOk()->assertSee('Same name')->assertSee('Same email');

        Livewire::test(Duplicates::class)->call('compare', $a->ulid, $b->ulid)->assertSee('Keep this record')
            ->set('keep', 'b')->call('merge')->assertRedirect(route('app.customers.show', $b));
        $this->assertTrue($a->fresh()->trashed());
        $this->assertSame('+15125550101', $b->fresh()->phone_e164);

        // From a customer's page: pick any other record.
        $c = Customer::create(['organization_id' => $org->id, 'first_name' => 'Carla', 'phone' => '5125550104']);
        Livewire::test(Duplicates::class, ['first' => $c->ulid])->set('search', 'Zed')->assertSee('Zed')->call('pick', Customer::where('first_name', 'Zed')->sole()->ulid)
            ->assertSee('Keep this record');
    }

    public function test_staff_cannot_merge_and_other_businesses_are_out_of_reach(): void
    {
        [$owner, $org] = $this->business();
        [, $other] = $this->business();
        $mine = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'phone' => '5125550101']);
        $theirs = Customer::create(['organization_id' => $other->id, 'first_name' => 'Ana', 'phone' => '5125550101']);

        $staff = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $org->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->actingAs($staff)->get(route('app.customers.duplicates'))->assertForbidden();

        $this->actingAs($owner);
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(Duplicates::class)->call('compare', $mine->ulid, $theirs->ulid)->call('merge');
    }
}
