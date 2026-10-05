<?php

namespace App\Services\Account;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Authenticator-app codes (TOTP, RFC 6238) and one-time recovery codes (D8).
 * Recovery codes are stored hashed; each works once. A code can't be replayed within its window.
 */
class TwoFactor
{
    public function __construct(private readonly Google2FA $engine) {}

    public function newSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->engine->getQRCodeUrl((string) config('account.two_factor.issuer'), $user->email, $secret);
    }

    /** Inline SVG of the setup QR code; drawn on the server so the secret never leaves our pages. */
    public function qrSvg(User $user, string $secret): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($this->otpauthUrl($user, $secret));

        return trim(substr($svg, strpos($svg, "\n") + 1));   // drop the XML declaration
    }

    /** Check a 6-digit code against a secret, accepting one step of clock drift and refusing replays. */
    public function verify(string $secret, string $code, ?int $userId = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }

        $key = 'two-factor:last:'.($userId ?? sha1($secret));
        // With a previous step given, the engine returns the matching step, which must be newer.
        $step = $this->engine->verifyKeyNewer($secret, $code, (int) Cache::get($key, 0), 1);
        if ($step === false || $step === true) {
            return false;
        }
        Cache::put($key, $step, now()->addMinutes(5));

        return true;
    }

    /**
     * Fresh recovery codes: the plain codes to show once, and their hashes to store.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public function recoveryCodes(): array
    {
        $plain = collect(range(1, (int) config('account.two_factor.recovery_codes', 8)))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();

        return [$plain, array_map(fn (string $c) => Hash::make($c), $plain)];
    }

    /** Use up a recovery code. True when it matched one that hadn't been used. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $code = Str::lower(trim($code));
        $hashes = $user->two_factor_recovery_codes ?? [];
        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

                return true;
            }
        }

        return false;
    }

    /** A sign-in code: an authenticator code, or else a recovery code. */
    public function check(User $user, string $code): bool
    {
        if (! $user->hasTwoFactor()) {
            return false;
        }

        return $this->verify((string) $user->two_factor_secret, $code, $user->id) || (str_contains($code, '-') && $this->useRecoveryCode($user, $code));
    }
}
