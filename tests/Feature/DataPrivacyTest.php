<?php

namespace Tests\Feature;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Billing\IssueInvoice;
use App\Actions\Customers\RecordTimelineEvent;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\OrganizationStatus;
use App\Enums\TimelineEventType;
use App\Livewire\Client\Customers\Show as CustomerShow;
use App\Livewire\Client\Settings\Privacy;
use App\Models\AuditLog;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\CustomerTimelineEvent;
use App\Models\DataExport;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AccountClosureScheduled;
use App\Notifications\DataExportReady;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * Data export, retention, erasing a customer and closing an account (spec §56–57, §91; CMP-05, CMP-07).
 */
class DataPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Organization} */
    private function business(string $name = 'Rivera Plumbing'): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'password' => Hash::make('Secret123!'), 'name' => 'Maria Rivera']);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['name' => $name, 'setup_completed_at' => now(), 'status' => OrganizationStatus::Active])->save();

        return [$owner, $org->fresh()];
    }

    private function logCall(Organization $org, ?Customer $customer = null, string $ago = 'now', array $extra = []): CallLog
    {
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $call = CallLog::create($extra + ['user_id' => $agent->id, 'organization_id' => $org->id, 'customer_id' => $customer?->id, 'call_id' => CallLog::generateCallId(),
            'call_date' => now()->toDateString(), 'call_time' => '09:00', 'caller_name' => 'Ana Lopez', 'caller_phone' => '5125550101', 'caller_email' => 'ana@example.test',
            'reason_for_call' => 'service-request', 'call_outcome' => 'other', 'agent_name' => 'A', 'status' => 'new', 'notes' => 'Leaking pipe at 12 Oak St']);
        $call->forceFill(['created_at' => now()->parse($ago)])->save();

        return $call;
    }

    public function test_owners_download_a_full_export_and_nobody_else_can(): void
    {
        Notification::fake();
        [$owner, $org] = $this->business();
        $customer = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '5125550101', 'notes' => '=HYPERLINK("x")']);
        $this->logCall($org, $customer);

        $this->actingAs($owner)->get(route('app.settings.privacy'))->assertOk()->assertSee('Download your data')->assertSee('Close your account');
        Livewire::test(Privacy::class)->call('requestExport');

        $export = DataExport::sole();
        $this->assertSame(DataExport::READY, $export->status);
        Notification::assertSentTo($owner, DataExportReady::class);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->path)));
        foreach (['customers.csv', 'calls.csv', 'appointments.csv', 'tasks.csv', 'escalations.csv', 'invoices.csv', 'business.json', 'README.txt'] as $file) {
            $this->assertNotFalse($zip->locateName($file), "$file is in the export");
        }
        $customers = $zip->getFromName('customers.csv');
        $this->assertStringContainsString('Ana', $customers);
        $this->assertStringContainsString("'=HYPERLINK", $customers, 'formulas are neutralised');
        $this->assertStringContainsString('Leaking pipe', $zip->getFromName('calls.csv'));
        $zip->close();

        $this->get(route('app.settings.privacy.export', $export))->assertOk()->assertDownload();
        $this->assertTrue(AuditLog::where('action', 'data_export.downloaded')->exists());

        // Another business can't fetch it; managers can't open the page.
        [$other] = $this->business('Other Co');
        $this->actingAs($other)->get(route('app.settings.privacy.export', $export))->assertNotFound();
        $manager = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $org->members()->attach($manager->id, ['role' => 'manager', 'status' => 'active']);
        $this->actingAs($manager)->get(route('app.settings.privacy'))->assertForbidden();

        // After 7 days the file is gone.
        $this->travel(8)->days();
        $this->artisan('privacy:run')->expectsOutputToContain('expired 1 export');
        Storage::disk('local')->assertMissing($export->path);
        $this->actingAs($owner)->get(route('app.settings.privacy.export', $export))->assertStatus(410);
    }

    public function test_export_size_label_needs_no_intl_extension(): void
    {
        $label = fn (?int $bytes) => (new DataExport(['size_bytes' => $bytes]))->forceFill(['size_bytes' => $bytes])->sizeLabel();

        $this->assertNull($label(null));
        $this->assertSame('512 B', $label(512));
        $this->assertSame('1 KB', $label(1024));
        $this->assertSame('840 KB', $label(840 * 1024));
        $this->assertSame('2.4 MB', $label((int) (2.4 * 1024 * 1024)));
    }

    public function test_one_export_at_a_time_and_three_a_day(): void
    {
        Bus::fake();
        [$owner] = $this->business();
        $this->actingAs($owner);

        Livewire::test(Privacy::class)->call('requestExport')->call('requestExport');
        $this->assertSame(1, DataExport::count(), 'one already being prepared');

        DataExport::query()->update(['status' => DataExport::READY]);
        Livewire::test(Privacy::class)->call('requestExport');
        DataExport::query()->update(['status' => DataExport::READY]);
        Livewire::test(Privacy::class)->call('requestExport');
        DataExport::query()->update(['status' => DataExport::READY]);
        Livewire::test(Privacy::class)->call('requestExport');
        $this->assertSame(3, DataExport::count());
    }

    public function test_history_older_than_the_chosen_period_is_deleted_but_open_work_stays(): void
    {
        Bus::fake();
        [$owner, $org] = $this->business();
        $this->assertSame(36, $org->retention_months, 'three years by default');

        $old = $this->logCall($org, ago: '-40 months');
        $recent = $this->logCall($org, ago: '-2 months');
        $doneTask = Task::create(['organization_id' => $org->id, 'title' => 'Old done', 'status' => 'completed']);
        $doneTask->forceFill(['updated_at' => now()->subMonths(40)])->saveQuietly();
        $openTask = Task::create(['organization_id' => $org->id, 'title' => 'Old open', 'status' => 'open']);
        $openTask->forceFill(['updated_at' => now()->subMonths(40)])->saveQuietly();

        $this->artisan('privacy:run')->expectsOutputToContain('1 calls');
        $this->assertNull(CallLog::find($old->id));
        $this->assertNotNull(CallLog::find($recent->id));
        $this->assertNull(Task::withoutGlobalScopes()->find($doneTask->id));
        $this->assertNotNull(Task::withoutGlobalScopes()->find($openTask->id));

        // Shorter period, chosen by the owner.
        $this->actingAs($owner);
        Livewire::test(Privacy::class)->set('retention', '7')->call('saveRetention')->assertHasErrors('retention')
            ->set('retention', '12')->call('saveRetention')->assertHasNoErrors();
        $this->logCall($org, ago: '-13 months');
        $this->artisan('privacy:run')->expectsOutputToContain('1 calls');

        // "Keep everything" keeps everything.
        Livewire::test(Privacy::class)->set('retention', '')->call('saveRetention');
        $this->assertNull($org->fresh()->retention_months);
        $this->logCall($org, ago: '-10 years');
        $this->artisan('privacy:run')->expectsOutputToContain('retention deleted: nothing');
    }

    public function test_erasing_a_customer_removes_their_details_everywhere(): void
    {
        Bus::fake();
        [$owner, $org] = $this->business();
        $ana = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '5125550101', 'email' => 'ana@example.test', 'city' => 'Austin']);
        $call = $this->logCall($org, $ana);
        $other = $this->logCall($org, null, extra: ['caller_name' => 'Ben Ortiz']);
        $appointment = app(BookAppointment::class)->handle($org, ['starts_at' => now()->addDay(), 'title' => 'Leak repair for Ana', 'customer_id' => $ana->id, 'address' => '12 Oak St', 'status' => 'pending']);
        app(RecordTimelineEvent::class)->handle($ana, TimelineEventType::NoteAdded, 'Called about a leak');

        $this->actingAs($owner);
        Livewire::test(CustomerShow::class, ['customer' => $ana->ulid])->assertSee('Erase personal data')->call('erase')->assertRedirect(route('app.customers.index'));

        $ana = Customer::withTrashed()->find($ana->id);
        $this->assertTrue($ana->trashed());
        $this->assertNotNull($ana->erased_at);
        $this->assertNull($ana->email);
        $this->assertNull($ana->phone_e164);
        $this->assertNull($ana->city);
        $this->assertSame(0, CustomerTimelineEvent::withoutGlobalScopes()->where('customer_id', $ana->id)->count());

        $call->refresh();
        $this->assertSame('Erased customer', $call->caller_name);
        $this->assertNull($call->caller_phone);
        $this->assertNull($call->notes);
        $this->assertSame('other', $call->call_outcome, 'outcomes stay for the results');
        $this->assertSame('Ben Ortiz', $other->fresh()->caller_name);
        $this->assertNull($appointment->fresh()->address);
        $this->assertStringNotContainsString('Ana', $appointment->fresh()->title);

        $log = AuditLog::where('action', 'customer.erased')->sole();
        $this->assertStringNotContainsString('Ana', json_encode($log->toArray()));

        // The phone number is free for a new record.
        Customer::create(['organization_id' => $org->id, 'first_name' => 'New', 'phone' => '5125550101']);

        // Staff can't erase.
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $org->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $ben = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ben']);
        $this->actingAs($staff);
        Livewire::test(CustomerShow::class, ['customer' => $ben->ulid])->assertDontSee('Erase personal data')->call('erase')->assertForbidden();
    }

    public function test_closing_an_account_waits_30_days_then_deletes_the_business_data(): void
    {
        Notification::fake();
        Bus::fake();
        [$owner, $org] = $this->business();
        $customer = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'phone' => '5125550101']);
        $call = $this->logCall($org, $customer);
        $invoice = app(IssueInvoice::class)->issue($org, [['description' => 'Setup', 'quantity' => 1, 'unit_cents' => 5000]]);
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'email' => 'sam@rivera.test']);
        $org->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $owner->createToken('phone');

        $this->actingAs($owner);
        Livewire::test(Privacy::class)->set('confirmName', 'Rivera')->set('password', 'wrong')->call('closeAccount')
            ->assertHasErrors(['confirmName', 'password']);
        Livewire::test(Privacy::class)->set('confirmName', 'rivera plumbing')->set('password', 'Secret123!')->call('closeAccount')->assertHasNoErrors()
            ->assertSee('Keep my account');
        $this->assertTrue($org->fresh()->isClosing());
        Notification::assertSentTo($owner, AccountClosureScheduled::class);
        $this->get(route('app.dashboard'))->assertSee('Your account closes on');

        // Changing their mind.
        Livewire::test(Privacy::class)->call('keepAccount');
        $this->assertFalse($org->fresh()->isClosing());

        Livewire::test(Privacy::class)->set('confirmName', 'Rivera Plumbing')->set('password', 'Secret123!')->call('closeAccount');
        $this->travel(29)->days();
        $this->artisan('privacy:run')->expectsOutputToContain('Closed 0 accounts');
        $this->travel(2)->days();
        $this->artisan('privacy:run')->expectsOutputToContain('Closed 1 account');

        $org->refresh();
        $this->assertSame(OrganizationStatus::Cancelled, $org->status);
        $this->assertNotNull($org->closed_at);
        $this->assertNull(CallLog::withoutGlobalScopes()->find($call->id));
        $this->assertSame(0, Customer::withTrashed()->withoutGlobalScopes()->where('organization_id', $org->id)->count());
        $this->assertNotNull(Invoice::withoutGlobalScopes()->find($invoice->id), 'invoices are kept');

        $owner->refresh();
        $this->assertFalse($owner->is_active);
        $this->assertSame('Former user', $owner->name);
        $this->assertStringEndsWith('@invalid.surehelp', $owner->email);
        $this->assertSame(0, $owner->tokens()->count());
        $this->assertSame(0, $org->members()->count());
        $this->assertFalse($staff->fresh()->is_active);

        // Password guessing is limited.
        [$third] = $this->business('Third Co');
        $this->actingAs($third);
        foreach (range(1, 5) as $i) {
            Livewire::test(Privacy::class)->set('confirmName', 'Third Co')->set('password', 'guess'.$i)->call('closeAccount')->assertHasErrors('password');
        }
        Livewire::test(Privacy::class)->set('confirmName', 'Third Co')->set('password', 'Secret123!')->call('closeAccount')->assertHasErrors('password');
        $this->assertNull(Organization::where('name', 'Third Co')->sole()->closes_at);

        // Only the owner may close.
        [, $second] = $this->business('Second Co');
        $manager = User::factory()->create(['role' => 'client', 'is_active' => true, 'password' => Hash::make('Secret123!')]);
        $second->members()->attach($manager->id, ['role' => 'manager', 'status' => 'active']);
        $this->actingAs($manager)->get(route('app.settings.privacy'))->assertForbidden();
    }
}
