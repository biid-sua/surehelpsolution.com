<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PragmaRX\Google2FA\Google2FA;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests don't depend on built front-end assets (public/build).
        $this->withoutVite();
    }

    /** Turn on two-step sign-in for a user; returns the secret. */
    protected function enableTwoFactor(User $user): string
    {
        $secret = app(Google2FA::class)->generateSecretKey(32);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()])->save();

        return $secret;
    }

    /** The current authenticator code for a secret. */
    protected function twoFactorCode(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }
}
