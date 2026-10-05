<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * P1-6 — API envelope and errors (spec §49, §60), security (spec §50, §58, §61), device management (D8).
 */
class ApiConventionsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'agent', array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true, 'password' => 'Secret123!'], $attributes));
    }

    // Errors ----------------------------------------------------------------

    public function test_unauthenticated_requests_get_the_envelope(): void
    {
        $this->getJson('/api/v1/user')->assertStatus(401)->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);

        // Even without an Accept header, /api answers in JSON.
        $this->get('/api/v1/user')->assertStatus(401)->assertJson(['success' => false]);
    }

    public function test_forbidden_not_found_and_method_errors_use_the_envelope(): void
    {
        $client = $this->user('client');
        $this->actingAs($client, 'sanctum');

        $this->getJson('/api/v1/agent/call-logs')->assertStatus(403)->assertJson(['success' => false]);
        $this->getJson('/api/v1/does-not-exist')->assertStatus(404)->assertExactJson(['success' => false, 'message' => 'Not found.']);
        $this->deleteJson('/api/v1/user')->assertStatus(405)->assertJson(['success' => false, 'message' => 'Method not allowed.']);
    }

    public function test_server_errors_never_leak_internals(): void
    {
        Route::middleware('api')->get('/api/v1/__boom', fn () => throw new \RuntimeException('SQLSTATE secret table detail'));

        $response = $this->getJson('/api/v1/__boom')->assertStatus(500);

        $response->assertExactJson(['success' => false, 'message' => 'Something went wrong on our side. Please try again.']);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_validation_errors_use_the_envelope(): void
    {
        $this->postJson('/api/v1/login', [])->assertStatus(422)->assertJsonStructure(['success', 'message', 'errors' => ['email', 'password']]);
    }

    public function test_rate_limit_answers_429_with_retry_after(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => 'x@example.test', 'password' => 'wrongpass']);
        }

        $this->postJson('/api/v1/login', ['email' => 'x@example.test', 'password' => 'wrongpass'])
            ->assertStatus(429)
            ->assertJson(['success' => false])
            ->assertJsonStructure(['retry_after'])
            ->assertHeader('Retry-After');
    }

    public function test_denied_requests_are_logged_to_the_security_channel(): void
    {
        $channel = \Mockery::mock();
        $channel->shouldReceive('warning')->once()->with('authorization.denied', \Mockery::on(
            fn (array $context) => $context['path'] === '/api/v1/agent/call-logs' && $context['user_id'] !== null && array_key_exists('ip', $context)
        ));
        Log::shouldReceive('channel')->with('security')->andReturn($channel);

        $this->actingAs($this->user('client'), 'sanctum');
        $this->postJson('/api/v1/agent/call-logs', [])->assertStatus(403);
    }

    // Headers ---------------------------------------------------------------

    public function test_security_headers_and_no_store_on_api(): void
    {
        $this->get('/')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $this->actingAs($this->user(), 'sanctum');
        $cache = $this->getJson('/api/v1/user')->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
    }

    public function test_cors_only_allows_our_own_origin(): void
    {
        config(['cors.allowed_origins' => ['https://surehelpsolution.com']]);

        $this->withHeaders(['Origin' => 'https://surehelpsolution.com', 'Access-Control-Request-Method' => 'GET'])
            ->options('/api/v1/user')
            ->assertHeader('Access-Control-Allow-Origin', 'https://surehelpsolution.com');

        // A foreign origin is never echoed back, so browsers block the cross-site call.
        $evil = $this->withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'GET'])->options('/api/v1/user');
        $this->assertNotSame('https://evil.example', $evil->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $evil->headers->get('Access-Control-Allow-Origin'));
    }

    // Devices ---------------------------------------------------------------

    public function test_login_creates_a_named_expiring_device_token(): void
    {
        $user = $this->user('agent', ['email' => 'agent@surehelp.test']);

        $secret = $this->enableTwoFactor($user);
        $response = $this->postJson('/api/v1/login', ['email' => 'agent@surehelp.test', 'password' => 'Secret123!', 'device_name' => 'Pixel 9', 'two_factor_code' => $this->twoFactorCode($secret)])
            ->assertOk();

        $this->assertNotNull($response->json('data.expires_at'));
        $token = $user->tokens()->sole();
        $this->assertSame('Pixel 9', $token->name);
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(31)));

        // Refresh keeps the device name and is not recorded as a new login.
        $this->withToken($response->json('data.token'))->postJson('/api/v1/refresh-token')->assertOk();
        $this->assertSame(['Pixel 9'], $user->tokens()->pluck('name')->all());
        $this->assertSame(1, AuditLog::where('action', 'auth.login')->count());
        $this->assertSame(1, AuditLog::where('action', 'auth.token_refreshed')->count());
    }

    public function test_user_can_list_and_sign_out_devices(): void
    {
        $user = $this->user();
        $phone = $user->createToken('Phone')->plainTextToken;
        $tablet = $user->createToken('Tablet');
        $user->createToken('Old laptop');

        $devices = $this->withToken($phone)->getJson('/api/v1/devices')->assertOk()->json('data.devices');
        $this->assertCount(3, $devices);
        $this->assertSame(['Phone'], collect($devices)->where('current', true)->pluck('name')->all());
        $this->assertArrayNotHasKey('token', $devices[0]);

        $this->withToken($phone)->deleteJson('/api/v1/devices/'.$tablet->accessToken->id)->assertOk();
        $this->assertNull(PersonalAccessToken::find($tablet->accessToken->id));

        $this->withToken($phone)->deleteJson('/api/v1/devices')->assertOk()->assertJsonPath('data.revoked', 1);
        $this->assertSame(['Phone'], $user->tokens()->pluck('name')->all());

        $this->assertSame(1, AuditLog::where('action', 'auth.token_revoked')->count());
        $this->assertSame(1, AuditLog::where('action', 'auth.tokens_revoked')->count());
    }

    public function test_cannot_sign_out_someone_elses_device(): void
    {
        $me = $this->user();
        $other = $this->user();
        $theirs = $other->createToken('Their phone');

        $this->withToken($me->createToken('Mine')->plainTextToken)
            ->deleteJson('/api/v1/devices/'.$theirs->accessToken->id)
            ->assertNotFound();

        $this->assertNotNull(PersonalAccessToken::find($theirs->accessToken->id));
    }

    public function test_expired_tokens_are_rejected(): void
    {
        $user = $this->user();
        $token = $user->createToken('Old phone', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/user')->assertStatus(401);
    }
}
