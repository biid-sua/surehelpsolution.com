<?php

namespace Tests\Feature;

use App\Actions\Billing\IssueInvoice;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\SocialNetwork;
use App\Models\BusinessHour;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\Account\TeamInvitation;
use App\Services\Ai\Contracts\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * Mobile API (D73): writing social posts (API-9), billing (API-11) and the team (API-12).
 */
class MobileApiBusinessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $business;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'name' => 'Elena Rivera']);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->update(['name' => 'Rivera Plumbing', 'timezone' => 'America/Chicago']);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business->members()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function account(SocialNetwork $network): SocialAccount
    {
        return SocialAccount::create(['organization_id' => $this->business->id, 'network' => $network, 'external_id' => $network->value.'-1',
            'name' => $network->label().' page', 'access_token' => 't', 'is_enabled' => true]);
    }

    public function test_posts_are_written_checked_scheduled_and_cancelled_with_the_web_rules(): void
    {
        Storage::fake('local');
        $facebook = $this->account(SocialNetwork::Facebook);
        $google = $this->account(SocialNetwork::GoogleBusiness);

        Sanctum::actingAs($this->owner);
        $this->assertSame([$facebook->ulid, $google->ulid], array_column($this->getJson('/api/v1/client/social/accounts')->assertOk()->json('data.accounts'), 'id'));

        // Live checks per account: Google refuses phone numbers.
        $checks = $this->postJson('/api/v1/client/social/posts/check', ['body' => 'Call us at (512) 555-0100', 'accounts' => [$facebook->ulid, $google->ulid]])->json('data.checks');
        $this->assertSame([], $checks[0]['errors']);
        $this->assertNotEmpty($checks[1]['errors']);

        $media = $this->post('/api/v1/client/social/media', ['photo' => UploadedFile::fake()->image('van.jpg', 800, 600)], ['Accept' => 'application/json'])->assertCreated()->json('data.media.id');

        $this->postJson('/api/v1/client/social/posts', ['intent' => 'schedule', 'body' => 'Call us at (512) 555-0100', 'accounts' => [$google->ulid], 'scheduled_at' => now()->addDay()->toIso8601String()])
            ->assertStatus(422);
        $post = $this->postJson('/api/v1/client/social/posts', ['intent' => 'schedule', 'body' => 'Spring tune-ups are open.', 'accounts' => [$facebook->ulid, $google->ulid],
            'custom' => [$facebook->ulid => 'Spring tune-ups are open! Book today.'], 'media' => [$media], 'scheduled_at' => now()->addDay()->toIso8601String(), 'ai_assisted' => true])
            ->assertCreated()->assertJsonPath('message', 'Scheduled.')->json('data.post');
        $this->assertSame(['scheduled', 'AI-assisted', true], [$post['status'], $post['ai_label'], $post['editable']]);
        $this->assertSame('Spring tune-ups are open! Book today.', $post['custom'][$facebook->ulid]);
        $this->assertSame([$media], $post['media_ids']);

        $this->putJson("/api/v1/client/social/posts/{$post['id']}", ['intent' => 'draft', 'body' => 'Changed my mind.', 'accounts' => [$facebook->ulid]])->assertOk()->assertJsonPath('data.post.status', 'draft');
        $this->postJson("/api/v1/client/social/posts/{$post['id']}/cancel")->assertOk()->assertJsonPath('data.post.status', 'cancelled');

        // A manager's post waits for the owner.
        Sanctum::actingAs($this->member('manager'));
        $this->postJson('/api/v1/client/social/posts', ['intent' => 'schedule', 'body' => 'From the manager', 'accounts' => [$facebook->ulid], 'scheduled_at' => now()->addDays(2)->toIso8601String()])
            ->assertCreated()->assertJsonPath('data.post.status', 'in_review');

        // Staff can't manage social posts.
        Sanctum::actingAs($this->member('staff'));
        $this->postJson('/api/v1/client/social/posts', ['intent' => 'draft', 'body' => 'x'])->assertForbidden();
    }

    public function test_ai_writing_openings_and_the_link_page(): void
    {
        $ai = new ScriptedAiProvider;
        $this->app->instance(AiProvider::class, $ai);
        $facebook = $this->account(SocialNetwork::Facebook);
        $ai->reply(json_encode(['posts' => [['network' => 'facebook', 'text' => 'Fresh water heaters, installed fast.']], 'unsupported_claims' => []]));
        $ai->reply(json_encode(['text' => 'Water heaters, fast.', 'unsupported_claims' => []]));

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/client/social/ai/write', ['brief' => 'Water heater installs', 'accounts' => [$facebook->ulid]])->assertOk()
            ->assertJsonPath('data.versions.facebook', 'Fresh water heaters, installed fast.');
        $this->postJson('/api/v1/client/social/ai/rewrite', ['text' => 'Fresh water heaters, installed fast.', 'how' => 'shorter'])->assertOk()
            ->assertJsonPath('data.text', 'Water heaters, fast.');

        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $this->business->id, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00']);
        }
        $this->assertNotNull($this->getJson('/api/v1/client/social/openings')->assertOk()->json('data.text'));

        $this->putJson('/api/v1/client/social/link-page', ['enabled' => true, 'links' => [['label' => 'Reviews', 'url' => 'https://g.page/r']]])->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.links.0.label', 'Reviews');
        $this->putJson('/api/v1/client/social/link-page', ['enabled' => true, 'links' => [['label' => 'Bad', 'url' => 'nope']]])->assertStatus(422);
    }

    public function test_billing_shows_invoices_and_owners_pay_by_card(): void
    {
        config(['billing.gateway' => 'stripe', 'billing.stripe' => ['secret' => 'sk_test_123', 'webhook_secret' => 'whsec_test']]);
        $invoice = app(IssueInvoice::class)->issue($this->business->fresh(), [['description' => 'Receptionist plan', 'quantity' => 1, 'unit_cents' => 29900]]);
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
        ]);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/client/billing')->assertOk()->assertJsonPath('data.balance_cents', 29900)->assertJsonPath('data.card_payments', true)->assertJsonPath('data.can_pay', true);
        $this->assertSame([$invoice->ulid], array_column($this->getJson('/api/v1/client/billing/invoices?status=open')->json('data.invoices'), 'id'));
        $this->getJson("/api/v1/client/billing/invoices/{$invoice->ulid}")->assertOk()->assertJsonPath('data.invoice.items.0.description', 'Receptionist plan');
        $this->get("/api/v1/client/billing/invoices/{$invoice->ulid}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson("/api/v1/client/billing/invoices/{$invoice->ulid}/pay", ['save' => true])->assertOk()->assertJsonPath('data.checkout_url', 'https://checkout.stripe.com/c/pay/cs_1');
        $this->postJson("/api/v1/client/billing/invoices/{$invoice->ulid}/report-payment", ['note' => 'Bank transfer on Nov 3'])->assertOk();
        $this->assertNotNull(Invoice::query()->find($invoice->id)->client_reported_paid_at);

        // Managers see billing but can't pay.
        Sanctum::actingAs($this->member('manager'));
        $this->getJson('/api/v1/client/billing')->assertOk()->assertJsonPath('data.can_pay', false);
        $this->postJson("/api/v1/client/billing/invoices/{$invoice->ulid}/pay")->assertForbidden();
    }

    public function test_the_team_with_the_web_rules(): void
    {
        $manager = $this->member('manager');
        $staff = $this->member('staff');

        Sanctum::actingAs($this->owner);
        $data = $this->getJson('/api/v1/client/team')->assertOk()->json('data');
        $this->assertSame(['owner', 'manager', 'staff'], array_column($data['members'], 'role'));
        $this->assertSame(['manager', 'staff'], $data['can_invite']);

        $this->postJson('/api/v1/client/team/invitations', ['email' => 'New@Rivera.test', 'role' => 'manager'])->assertCreated()->assertJsonPath('data.invitation.email', 'new@rivera.test');
        Notification::assertSentOnDemand(TeamInvitation::class);
        $invitation = OrganizationInvitation::query()->sole();
        $this->deleteJson("/api/v1/client/team/invitations/{$invitation->id}")->assertOk();
        $this->assertNotNull($invitation->fresh()->revoked_at);

        $this->patchJson("/api/v1/client/team/members/{$staff->id}", ['role' => 'manager'])->assertOk()->assertJsonPath('data.role', 'manager');
        $this->patchJson("/api/v1/client/team/members/{$this->owner->id}", ['role' => 'staff'])->assertForbidden();
        $this->deleteJson("/api/v1/client/team/members/{$staff->id}")->assertOk();
        $this->assertFalse($staff->fresh()->is_active);

        // Managers invite staff only and can't change roles.
        Sanctum::actingAs($manager);
        $this->assertSame(['staff'], $this->getJson('/api/v1/client/team')->json('data.can_invite'));
        $this->postJson('/api/v1/client/team/invitations', ['email' => 'm2@rivera.test', 'role' => 'manager'])->assertStatus(422);
        $this->patchJson("/api/v1/client/team/members/{$this->owner->id}", ['role' => 'staff'])->assertForbidden();
    }
}
