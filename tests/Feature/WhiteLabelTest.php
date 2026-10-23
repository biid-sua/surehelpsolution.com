<?php

namespace Tests\Feature;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Client\Business\Branding;
use App\Models\Appointment;
use App\Models\BusinessProfile;
use App\Models\ChatWidget;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CustomerEmail;
use App\Services\Appointments\CustomerSelfService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * White-label (D82): a business's colour, sender name and look on customer emails, its pages and its portal.
 */
class WhiteLabelTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'UTC'));
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->org = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->org->forceFill(['name' => 'Rivera Plumbing', 'slug' => 'rivera-plumbing', 'timezone' => 'UTC', 'status' => 'active', 'setup_completed_at' => now()])->save();
        $this->org = $this->org->fresh();
        BusinessProfile::create(['organization_id' => $this->org->id, 'display_name' => 'Rivera Plumbing', 'email' => 'office@rivera.test', 'link_page_enabled' => true]);
        $this->actingAs($this->owner);
    }

    private function grant(): void
    {
        DB::table('organization_features')->insert(['organization_id' => $this->org->id, 'feature' => 'white_label', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{0: CustomerEmail, 1: string} the confirmation email and its HTML */
    private function confirmation(): array
    {
        $customer = Customer::create(['organization_id' => $this->org->id, 'first_name' => 'Ana', 'email' => 'ana@example.test', 'phone' => '5125550101']);
        app(BookAppointment::class)->handle($this->org, ['starts_at' => now()->addDays(3), 'title' => 'Leak repair', 'customer_id' => $customer->id, 'status' => 'confirmed']);
        $sent = null;
        Notification::assertSentTo(new AnonymousNotifiable, CustomerEmail::class, function (CustomerEmail $n) use (&$sent) {
            $sent = $n;

            return true;
        });
        $mail = $sent->toMail(new AnonymousNotifiable);

        return [$sent, (string) $mail->render()];
    }

    public function test_without_the_plan_feature_it_stays_surehelp_branded(): void
    {
        Livewire::test(Branding::class)->assertSee('Not in your plan yet')->call('save')->assertForbidden();

        [$email, $html] = $this->confirmation();
        $mail = $email->toMail(new AnonymousNotifiable);
        $this->assertSame('Rivera Plumbing', $mail->from[1]);
        $this->assertStringContainsString('sent with SureHelp Solutions', $html);
        $this->assertStringContainsString('#4f46e5', $html);

        $this->get(route('link-page', 'rivera-plumbing'))->assertOk()->assertSee('handled by SureHelp Solutions');
        $this->get(route('app.business.automations'))->assertOk()->assertSee('alt="SureHelp Solutions"', false);
    }

    public function test_white_label_brands_emails_pages_and_the_portal(): void
    {
        $this->grant();
        $widget = ChatWidget::create(['organization_id' => $this->org->id, 'public_key' => 'pk_test_rivera']);

        Livewire::test(Branding::class)->set('color', 'blue')->call('save')->assertHasErrors('color')
            ->set('color', '#0F766E')->set('sender', 'Rivera <x@y.z>')->call('save')->assertHasErrors('sender')
            ->set('sender', 'The Rivera Team')->call('save')->assertHasNoErrors()->assertRedirect(route('app.business.branding'));

        $profile = BusinessProfile::query()->where('organization_id', $this->org->id)->sole();
        $this->assertSame('#0f766e', $profile->brand_color);
        $this->assertSame('The Rivera Team', $profile->email_sender_name);
        $this->assertSame('#0f766e', $widget->fresh()->color);

        [$email, $html] = $this->confirmation();
        $mail = $email->toMail(new AnonymousNotifiable);
        $this->assertSame('The Rivera Team', $mail->from[1]);
        $this->assertSame('The Rivera Team', $mail->replyTo[0][1]);
        $this->assertStringContainsString('#0f766e', $html);
        $this->assertStringNotContainsString('SureHelp', $html);

        $this->get(route('link-page', 'rivera-plumbing'))->assertOk()->assertDontSee('handled by SureHelp')->assertSee('--color-brand-500:#0f766e', false);
        $this->get(route('app.business.automations'))->assertOk()->assertDontSee('alt="SureHelp Solutions"', false)
            ->assertSee('--color-brand-500:#0f766e', false)->assertSee('<title>Automations · Rivera Plumbing</title>', false);

        // Their customers' appointment page drops the SureHelp line too.
        $appointment = Appointment::withoutGlobalScopes()->where('organization_id', $this->org->id)->latest('id')->firstOrFail();
        $this->get(app(CustomerSelfService::class)->link($appointment))->assertOk()->assertDontSee('handled by SureHelp');

        // The portal look can be turned off, leaving customers' branding alone.
        Livewire::test(Branding::class)->set('portal', false)->call('save')->assertHasNoErrors();
        $this->get(route('app.business.automations'))->assertSee('alt="SureHelp Solutions"', false);
        $this->get(route('link-page', 'rivera-plumbing'))->assertDontSee('handled by SureHelp');
    }

    public function test_agents_and_staff_keep_the_surehelp_look(): void
    {
        $this->grant();
        BusinessProfile::query()->where('organization_id', $this->org->id)->update(['brand_color' => '#0f766e']);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);

        $this->actingAs($admin)->get(route('admin.home'))->assertOk()->assertDontSee('--color-brand-500:#0f766e', false);
    }
}
