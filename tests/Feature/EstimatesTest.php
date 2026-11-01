<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Client\Documents\Editor;
use App\Livewire\Client\Documents\Index;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\JobDocument;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Notifications\CustomerEmail;
use App\Notifications\EstimateAnswered;
use App\Services\Payments\CustomerPayments;
use App\Services\Telephony\TelephonyProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Estimates and invoices to customers (D115): written in the portal, sent by text and email, accepted
 * online, turned into an invoice and paid by card or by hand.
 */
class EstimatesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $business;

    private Customer $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00', 'UTC'));
        config(['telephony.sms' => true]);
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->forceFill(['name' => 'Rivera Plumbing', 'timezone' => 'America/Chicago', 'status' => 'active', 'setup_completed_at' => now()])->save();
        BusinessProfile::create(['organization_id' => $this->business->id, 'display_name' => 'Rivera Plumbing', 'email' => 'office@rivera.test']);
        PhoneNumber::create(['organization_id' => $this->business->id, 'provider' => 'fake', 'provider_sid' => 'PN1', 'number' => '+15125550100']);
        $this->ana = Customer::create(['organization_id' => $this->business->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '5125550147', 'email' => 'ana@lopez.test']);
        $this->enableTwoFactor($this->owner);
        $this->actingAs($this->owner);
    }

    private function writeEstimate(): JobDocument
    {
        Livewire::test(Editor::class)
            ->set('customerSearch', 'Ana')->call('pickCustomer', $this->ana->ulid)
            ->set('title', 'Water heater replacement')
            ->set('items', [
                ['description' => '50-gallon gas water heater', 'quantity' => '1', 'price' => '1,250'],
                ['description' => 'Labor (hours)', 'quantity' => '2.5', 'price' => '95'],
                ['description' => '', 'quantity' => '1', 'price' => ''],
            ])
            ->set('taxRate', '8.25')
            ->call('save')->assertHasNoErrors();

        return JobDocument::query()->forOrganization($this->business)->sole();
    }

    public function test_an_estimate_is_sent_accepted_invoiced_and_paid_by_card(): void
    {
        $estimate = $this->writeEstimate();
        // 1,250 + 2.5 × 95 = 1,487.50; tax 8.25% = 122.72.
        $this->assertSame(['EST-1001', 'draft', 148750, 12272, 161022, 2], [$estimate->number, $estimate->status, $estimate->subtotal_cents, $estimate->tax_cents, $estimate->total_cents, count($estimate->items)]);
        $this->assertSame('2026-11-19', $estimate->valid_until->toDateString());
        $this->get(route('documents.show', $estimate->token))->assertNotFound();   // a draft isn't shared yet

        Livewire::test(Editor::class, ['document' => $estimate])->call('send')->assertHasNoErrors()->assertDispatched('toast', type: 'success');
        $estimate->refresh();
        $this->assertSame(['sent', ['sms', 'email']], [$estimate->status, $estimate->sent_via]);
        $text = collect(app(TelephonyProvider::class)->texts)->last();
        $this->assertStringContainsString('Rivera Plumbing sent you an estimate for Water heater replacement ($1,610.22)', $text['body']);
        $this->assertStringContainsString($estimate->link(), $text['body']);
        Notification::assertSentOnDemand(CustomerEmail::class, fn (CustomerEmail $m, $c, $to) => $to->routes['mail'] === 'ana@lopez.test' && $m->actionUrl === $estimate->link());

        // Ana opens it and accepts by typing her name.
        $this->app['auth']->forgetGuards();
        $this->get(route('documents.show', $estimate->token))->assertOk()->assertSee('50-gallon gas water heater')->assertSee('$1,610.22')->assertSee('Tax (8.25%)');
        $this->post(route('documents.accept', $estimate->token), ['name' => ''])->assertSessionHasErrors('name');
        $this->post(route('documents.accept', $estimate->token), ['name' => 'Ana Lopez'])->assertRedirect(route('documents.show', $estimate->token));
        $estimate->refresh();
        $this->assertSame(['accepted', 'Ana Lopez'], [$estimate->status, $estimate->accepted_name]);
        $this->assertNotNull($estimate->viewed_at);
        Notification::assertSentTo($this->owner, EstimateAnswered::class);
        $this->post(route('documents.decline', $estimate->token))->assertSessionHas('document_error');   // already answered

        // The owner turns it into an invoice and sends it; Ana pays by card.
        $this->actingAs($this->owner);
        Livewire::test(Editor::class, ['document' => $estimate])->call('makeInvoice')->assertRedirect();
        $invoice = JobDocument::query()->forOrganization($this->business)->where('type', 'invoice')->sole();
        $this->assertSame(['INV-1001', 161022, $estimate->id, 'invoiced'], [$invoice->number, $invoice->total_cents, $invoice->estimate_id, $estimate->fresh()->status]);
        Livewire::test(Editor::class, ['document' => $invoice])->set('channels', ['email'])->call('send')->assertHasNoErrors();

        $this->readyForCards();
        $this->app['auth']->forgetGuards();
        $this->get(route('documents.show', $invoice->token))->assertOk()->assertSee('Pay $1,610.22 by card');
        $this->post(route('documents.pay', $invoice->token))->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
        $request = $invoice->fresh()->paymentRequest;
        $this->assertSame([161022, 'invoice', $this->ana->id], [$request->amount_cents, $request->kind, $request->customer_id]);
        app(CustomerPayments::class)->completed(['payment_status' => 'paid', 'amount_total' => 161022, 'payment_intent' => 'pi_1', 'metadata' => ['payment_request' => $request->ulid]], 'acct_rivera');
        $this->assertSame(['paid', 'card'], [$invoice->fresh()->status, $invoice->fresh()->paid_how]);
        $this->get(route('documents.show', $invoice->token))->assertSee('Paid, thank you!');
    }

    private function readyForCards(): void
    {
        config(['billing.stripe.secret' => 'sk_test_platform', 'billing.stripe.connect_webhook_secret' => 'whsec_x']);
        DB::table('organization_features')->insert(['organization_id' => $this->business->id, 'feature' => 'customer_payments', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        app()->forgetScopedInstances();
        PaymentAccount::create(['organization_id' => $this->business->id, 'stripe_account_id' => 'acct_rivera', 'charges_enabled' => true, 'currency' => 'usd']);
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);
    }

    public function test_declining_expiry_paying_by_hand_and_voiding(): void
    {
        $estimate = $this->writeEstimate();
        Livewire::test(Editor::class, ['document' => $estimate])->set('channels', ['sms'])->call('send');
        $this->app['auth']->forgetGuards();
        $this->post(route('documents.decline', $estimate->token), ['reason' => 'Got a cheaper quote'])->assertRedirect();
        $this->assertSame(['declined', 'Got a cheaper quote'], [$estimate->fresh()->status, $estimate->fresh()->declined_reason]);

        // Past its date, an estimate can't be accepted.
        $this->actingAs($this->owner);
        $late = $this->writeEstimate2();
        $this->travel(40)->days();
        $this->app['auth']->forgetGuards();
        $this->post(route('documents.accept', $late->token), ['name' => 'Ana'])->assertSessionHas('document_error');
        $this->assertSame('sent', $late->fresh()->status);

        // An invoice paid in cash; another voided.
        $this->actingAs($this->owner);
        Livewire::test(Editor::class, ['document' => $late])->call('makeInvoice');
        $invoice = JobDocument::query()->forOrganization($this->business)->where('type', 'invoice')->sole();
        Livewire::test(Editor::class, ['document' => $invoice])->set('paidHow', 'check')->call('markPaid')->assertDispatched('toast', type: 'success');
        $this->assertSame(['paid', 'check'], [$invoice->fresh()->status, $invoice->fresh()->paid_how]);
        Livewire::test(Editor::class, ['document' => $invoice])->set('title', 'Changed')->call('save')->assertHasErrors('title');
        Livewire::test(Editor::class, ['document' => $estimate])->call('void')->assertHasNoErrors();
        $this->assertSame('void', $estimate->fresh()->status);
        Livewire::test(Editor::class, ['document' => $invoice])->call('void')->assertHasErrors('title');   // a paid invoice stays paid
    }

    private function writeEstimate2(): JobDocument
    {
        Livewire::test(Editor::class)->call('pickCustomer', $this->ana->ulid)->set('title', 'Drain cleaning')
            ->set('items', [['description' => 'Main line', 'quantity' => '1', 'price' => '300']])->call('save')->assertHasNoErrors();
        $document = JobDocument::query()->forOrganization($this->business)->where('title', 'Drain cleaning')->sole();
        Livewire::test(Editor::class, ['document' => $document])->set('channels', ['email'])->call('send');

        return $document->fresh();
    }

    public function test_the_list_other_businesses_and_validation(): void
    {
        $estimate = $this->writeEstimate();
        Livewire::test(Editor::class)->set('title', 'No customer')->set('items', [['description' => 'X', 'quantity' => '1', 'price' => '10']])->call('save')->assertHasErrors('customer');
        Livewire::test(Editor::class)->call('pickCustomer', $this->ana->ulid)->set('title', 'No lines')->set('items', [['description' => '', 'quantity' => '1', 'price' => '']])->call('save')->assertHasErrors('items');
        Livewire::test(Editor::class)->call('pickCustomer', $this->ana->ulid)->set('title', 'Bad price')->set('items', [['description' => 'X', 'quantity' => '1', 'price' => 'lots']])->call('save')->assertHasErrors('items.0.price');

        $this->get(route('app.documents.index'))->assertOk()->assertSee('EST-1001')->assertSee('Water heater replacement');
        Livewire::test(Index::class)->set('type', 'invoice')->assertDontSee('EST-1001');
        $this->get(route('app.documents.edit', $estimate))->assertOk()->assertSee('$1,610.22');

        // Another business can't see or touch it, and its customers can't be used.
        $otherOwner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $other = app(ProvisionUserTenancy::class)->handle($otherOwner);
        $this->enableTwoFactor($otherOwner);
        $this->actingAs($otherOwner);
        $this->get(route('app.documents.edit', $estimate))->assertNotFound();
        $this->get(route('app.documents.index'))->assertOk()->assertDontSee('EST-1001');
        Livewire::test(Editor::class)->call('pickCustomer', $this->ana->ulid)->assertSet('customer', '');
        $this->assertSame(0, JobDocument::query()->forOrganization($other)->count());

        // Demo businesses never send anything.
        $this->actingAs($this->owner);
        $this->business->forceFill(['is_demo' => true])->save();
        Livewire::test(Editor::class, ['document' => $estimate])->call('send')->assertHasErrors('channels');
    }
}
