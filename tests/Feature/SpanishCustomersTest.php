<?php

namespace Tests\Feature;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Client\Business\Branding;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\ChatWidget;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Survey;
use App\Models\User;
use App\Models\Website;
use App\Notifications\CustomerEmail;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spanish for a business's customers (D92).
 */
class SpanishCustomersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'UTC'));   // Monday
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->org = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->org->forceFill(['name' => 'Rivera Plumbing', 'slug' => 'rivera-plumbing', 'timezone' => 'UTC', 'status' => 'active', 'setup_completed_at' => now()])->save();
        $this->org = $this->org->fresh();
        BusinessProfile::create(['organization_id' => $this->org->id, 'display_name' => 'Rivera Plumbing', 'phone' => '5125550100', 'link_page_enabled' => true]);
    }

    private function language(string $locale): void
    {
        $this->actingAs($this->owner);
        Livewire::test(Branding::class)->set('locale', $locale)->call('saveLanguage')->assertHasNoErrors();
        $this->assertSame($locale, BusinessProfile::query()->where('organization_id', $this->org->id)->value('customer_locale'));
        auth()->logout();
    }

    public function test_spanish_pages_emails_and_widget(): void
    {
        $this->language('es');

        // The standard confirmation email, in Spanish, with a Spanish date and button.
        $ana = Customer::create(['organization_id' => $this->org->id, 'first_name' => 'Ana', 'email' => 'ana@example.test', 'phone' => '5125550147']);
        app(BookAppointment::class)->handle($this->org, ['starts_at' => CarbonImmutable::parse('2026-10-16 10:00', 'UTC'), 'title' => 'Reparación', 'customer_id' => $ana->id, 'status' => 'confirmed']);
        Notification::assertSentTo(new AnonymousNotifiable, CustomerEmail::class, function (CustomerEmail $n) {
            return $n->subject === 'Tu cita con Rivera Plumbing está confirmada' && str_contains($n->body, 'Hola Ana,')
                && str_contains($n->body, 'viernes 16 de octubre') && $n->locale === 'es';
        });

        // Pages customers open.
        $this->get(route('link-page', 'rivera-plumbing'))->assertOk()->assertSee('lang="es"', false)->assertSee('Reservas y mensajes gestionados por SureHelp Solutions');
        $survey = Survey::create(['organization_id' => $this->org->id, 'customer_id' => $ana->id, 'channel' => 'sms', 'sent_at' => now(), 'expires_at' => now()->addDays(7)]);
        $this->get(route('survey.show', $survey->token))->assertOk()->assertSee('¿Cómo lo hicimos, Ana?')->assertSee('Toca un número');

        // The website widget.
        Website::create(['organization_id' => $this->org->id, 'url' => 'https://rivera.test/', 'host' => 'rivera.test', 'verified_at' => now()]);
        $widget = ChatWidget::for($this->org);
        $widget->update(['allowed_origins' => ['rivera.test'], 'features' => ['chat' => true, 'booking' => true, 'call' => false, 'lead' => true], 'bookings_need_confirmation' => false]);
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $this->org->id, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        }
        $call = fn (string $method, string $uri, ?array $body = null) => $this->call($method, '/api/chat/'.$widget->public_key.$uri, [], [], [],
            ['HTTP_ORIGIN' => 'https://rivera.test', 'CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => '203.0.113.9'], $body ? json_encode($body) : null);
        $call('GET', '/config?lang=en')->assertOk()->assertJsonPath('lang', 'es')->assertJsonPath('title', 'Chatea con nosotros');
        $call('POST', '/booking?lang=es', ['starts_at' => '2026-10-13 10:00', 'phone' => '512 555 0199'])->assertStatus(422)->assertJsonPath('message', 'Dinos tu nombre.');
        $this->assertStringStartsWith('Tu cita quedó reservada para el martes 13 octubre',
            $call('POST', '/booking?lang=es', ['starts_at' => '2026-10-13 10:00', 'name' => 'Bo', 'phone' => '512 555 0199'])->assertCreated()->json('message'));
        $this->get('/api/chat/widget.js')->assertOk()->assertSee('Elige un servicio', false);
    }

    public function test_auto_follows_the_browser_on_pages_and_stays_english_in_emails(): void
    {
        $this->language('auto');

        $this->get(route('link-page', 'rivera-plumbing'), ['Accept-Language' => 'es-MX,es;q=0.9'])->assertSee('Reservas y mensajes gestionados');
        $this->get(route('link-page', 'rivera-plumbing'), ['Accept-Language' => 'en-US'])->assertSee('Bookings and messages handled');

        $ana = Customer::create(['organization_id' => $this->org->id, 'first_name' => 'Ana', 'email' => 'ana@example.test', 'phone' => '5125550147']);
        app(BookAppointment::class)->handle($this->org, ['starts_at' => CarbonImmutable::parse('2026-10-16 10:00', 'UTC'), 'title' => 'Repair', 'customer_id' => $ana->id, 'status' => 'confirmed']);
        Notification::assertSentTo(new AnonymousNotifiable, CustomerEmail::class, fn (CustomerEmail $n) => $n->subject === 'Your appointment with Rivera Plumbing is confirmed' && $n->locale === 'en');
    }
}
