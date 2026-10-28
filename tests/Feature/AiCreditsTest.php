<?php

namespace Tests\Feature;

use App\Actions\Billing\ManageSubscription;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\VoidInvoice;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\OrganizationStatus;
use App\Enums\PaymentMethod;
use App\Livewire\Admin\Billing\Credits as AdminCredits;
use App\Livewire\Client\Billing\Index as ClientBilling;
use App\Livewire\Client\SiteBuilder;
use App\Models\CreditEntry;
use App\Models\CreditPurchase;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteEdit;
use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Billing\AiCredits;
use App\Services\Sites\SiteBuilder as Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * AI credits (D100): packs bought through an invoice and added once it's paid, used by website
 * changes beyond the monthly allowance, and managed by staff.
 */
class AiCreditsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private Organization $business;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $this->plan = Plan::create(['slug' => 'pro', 'name' => 'Pro', 'price_cents' => 29900]);
        [$this->owner, $this->business] = $this->business('Rivera Plumbing');
    }

    /** @return array{0: User, 1: Organization} */
    private function business(string $name, bool $subscribed = true): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $business = app(ProvisionUserTenancy::class)->handle($owner);
        $business->update(['name' => $name, 'status' => OrganizationStatus::Active]);
        if ($subscribed) {
            app(ManageSubscription::class)->subscribe($business, $this->plan, $this->admin, withTrial: false);
        }

        return [$owner, $business->fresh()];
    }

    private function pay(Invoice $invoice): void
    {
        app(RecordPayment::class)->handle($invoice, $invoice->balanceCents(), PaymentMethod::BankTransfer, 'REF-'.$invoice->id, $this->admin);
    }

    public function test_a_pack_is_invoiced_and_its_credits_arrive_when_paid(): void
    {
        Livewire::actingAs($this->owner)->test(ClientBilling::class)
            ->assertSee('AI credits')->assertSee('500 credits')->assertSee('$40')
            ->call('buyCredits', 1)->assertRedirect();

        $purchase = CreditPurchase::withoutGlobalScopes()->sole();
        $this->assertSame('pending', $purchase->status);
        $invoice = $purchase->invoice;
        $this->assertSame(4000, $invoice->total_cents);
        $this->assertSame('500 AI credits', $invoice->items()->sole()->description);
        $this->assertSame(0, app(AiCredits::class)->balance($this->business), 'nothing until it is paid');

        $this->pay($invoice);
        $this->assertSame('granted', $purchase->fresh()->status);
        $this->assertSame(500, app(AiCredits::class)->balance($this->business));
        app(AiCredits::class)->invoicePaid($invoice->fresh());
        $this->assertSame(500, app(AiCredits::class)->balance($this->business), 'added once');

        Livewire::actingAs($this->owner)->test(ClientBilling::class)->assertSee('500 credits bought')->assertSee('+500');
    }

    public function test_a_voided_credit_invoice_cancels_the_purchase(): void
    {
        $purchase = app(AiCredits::class)->buy($this->business, 0, $this->owner);
        app(VoidInvoice::class)->handle($purchase->invoice, $this->admin, 'Ordered by mistake');

        $this->assertSame('cancelled', $purchase->fresh()->status);
        $this->assertSame(0, app(AiCredits::class)->balance($this->business));
    }

    public function test_only_owners_of_a_business_with_a_plan_can_buy(): void
    {
        [$owner] = $this->business('No Plan Co', subscribed: false);
        Livewire::actingAs($owner)->test(ClientBilling::class)->call('buyCredits', 0)->assertDispatched('toast', type: 'warning');
        $this->assertSame(0, CreditPurchase::withoutGlobalScopes()->count());

        Livewire::actingAs($this->owner)->test(ClientBilling::class)->call('buyCredits', 99)->assertDispatched('toast', type: 'warning');
        $this->assertSame(0, CreditPurchase::withoutGlobalScopes()->count());
    }

    public function test_website_changes_beyond_the_allowance_use_credits(): void
    {
        $ai = new ScriptedAiProvider;
        $this->app->instance(AiProvider::class, $ai);
        config(['sites.monthly_tokens' => 10000]);
        $site = app(Builder::class)->create($this->business, 'bold', 'rivera', $this->owner);
        SiteEdit::create(['site_id' => $site->id, 'organization_id' => $this->business->id, 'prompt' => 'earlier', 'status' => 'done', 'input_tokens' => 9000, 'output_tokens' => 500]);

        // Allowance nearly used and no credits: blocked.
        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->set('prompt', 'Use dark green')->call('ask')->assertHasErrors('prompt')->assertSee('Buy AI credits');

        app(AiCredits::class)->grant($this->business, 20, 'Welcome gift', $this->admin);
        $content = $site->draft()->content;
        $content['theme']['accent'] = '#166534';
        $ai->reply(json_encode(['summary' => 'Changed the colour.', 'changed' => true, 'content' => $content]));
        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->set('prompt', 'Use dark green')->call('ask')->assertHasNoErrors();

        // 500 tokens were left this month; the change used 1,950, so 1,450 came from credits: 2 credits.
        $this->assertSame(18, app(AiCredits::class)->balance($this->business));
        $usage = CreditEntry::withoutGlobalScopes()->where('kind', 'usage')->sole();
        $this->assertSame(-2, $usage->credits);
        $this->assertStringContainsString('Use dark green', $usage->note);
        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->assertSee('AI credits:')->assertSee('18');
    }

    public function test_changes_within_the_allowance_use_no_credits(): void
    {
        $ai = new ScriptedAiProvider;
        $this->app->instance(AiProvider::class, $ai);
        $site = app(Builder::class)->create($this->business, 'bold', 'rivera', $this->owner);
        app(AiCredits::class)->grant($this->business, 10, 'Gift', $this->admin);
        $ai->reply(json_encode(['summary' => 'Nothing to change.', 'changed' => false, 'content' => $site->draft()->content]));

        Livewire::actingAs($this->owner)->test(SiteBuilder::class)->set('prompt', 'Is it fine?')->call('ask');
        $this->assertSame(10, app(AiCredits::class)->balance($this->business));
    }

    public function test_staff_set_packs_and_give_or_take_back_credits(): void
    {
        Livewire::actingAs($this->admin)->test(AdminCredits::class)
            ->set('packs', [['credits' => '250', 'price' => '25'], ['credits' => '0', 'price' => '5'], ['credits' => '1000', 'price' => '80']])
            ->call('savePacks')->assertHasNoErrors()->assertDispatched('toast', type: 'success');
        $this->assertSame([['credits' => 250, 'price_cents' => 2500], ['credits' => 1000, 'price_cents' => 8000]], app(AiCredits::class)->packs());
        Livewire::actingAs($this->owner)->test(ClientBilling::class)->assertSee('250 credits')->assertSee('$25')->assertDontSee('500 credits');

        Livewire::actingAs($this->admin)->test(AdminCredits::class)
            ->set('grant', ['organization_id' => (string) $this->business->id, 'credits' => '50', 'note' => 'Sorry for the outage'])->call('give')->assertHasNoErrors()
            ->set('grant', ['organization_id' => (string) $this->business->id, 'credits' => '-80', 'note' => 'Correction'])->call('give')->assertHasErrors('grant.credits')
            ->set('grant', ['organization_id' => (string) $this->business->id, 'credits' => '-20', 'note' => ''])->call('give')->assertHasErrors('grant.note');
        $this->assertSame(50, app(AiCredits::class)->balance($this->business));
        $this->assertDatabaseHas('audit_logs', ['action' => 'credits.granted', 'organization_id' => $this->business->id]);
    }

    public function test_credits_stay_with_their_own_business_and_only_staff_manage_them(): void
    {
        [$otherOwner, $other] = $this->business('Other Co');
        app(AiCredits::class)->grant($this->business, 75, 'Rivera gift', $this->admin);

        Livewire::actingAs($otherOwner)->test(ClientBilling::class)->assertDontSee('Rivera gift');
        Livewire::actingAs($otherOwner)->test(ClientBilling::class)->call('buyCredits', 0);
        $this->assertSame($other->id, CreditPurchase::withoutGlobalScopes()->sole()->organization_id);
        $this->assertSame(0, app(AiCredits::class)->balance($other));

        $this->actingAs($this->owner)->get(route('admin.billing', ['tab' => 'credits']))->assertForbidden();
        Livewire::actingAs($this->owner)->test(AdminCredits::class)->assertForbidden();
    }
}
