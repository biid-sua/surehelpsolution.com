<?php

namespace App\Livewire\Account;

use App\Models\AuditLog;
use App\Services\Account\SignIn;
use App\Services\Account\TwoFactor;
use App\Support\Account\DeviceName;
use App\Support\Audit\Audit;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Two-step sign-in, recovery codes, signed-in devices and recent sign-ins (D8, spec AUTH-02/03/06).
 * During setup the new secret travels encrypted in a locked property: the browser can't read or change it,
 * and nothing is saved until the person proves their app works with a first code.
 */
#[Layout('layouts.portal')]
#[Title('Security')]
class Security extends Component
{
    #[Locked]
    public ?string $pendingSecret = null;

    public string $code = '';

    public string $password = '';

    /** Recovery codes, shown once right after they're made. */
    public array $freshCodes = [];

    public function startSetup(TwoFactor $twoFactor): void
    {
        abort_if(auth()->user()->hasTwoFactor(), 409);
        $this->pendingSecret = Crypt::encryptString($twoFactor->newSecret());
        $this->reset('code');
        $this->resetValidation();
    }

    public function cancelSetup(): void
    {
        $this->pendingSecret = null;
    }

    public function confirmSetup(TwoFactor $twoFactor, Audit $audit): void
    {
        $user = auth()->user();
        $secret = $this->secret();
        if (! $secret || $user->hasTwoFactor()) {
            $this->cancelSetup();

            return;
        }
        $this->resetValidation();
        if (! $twoFactor->verify($secret, $this->code, $user->id)) {
            throw ValidationException::withMessages(['code' => 'That code isn\'t right. Check the app shows "SureHelp" with your email, and enter the current code.']);
        }

        [$plain, $hashes] = $twoFactor->recoveryCodes();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => $hashes, 'two_factor_confirmed_at' => now()])->save();
        session()->forget('two_factor.setup_required');
        $audit->record('auth.two_factor_enabled', $user);

        $this->pendingSecret = null;
        $this->freshCodes = $plain;
        $this->reset('code');
        $this->dispatch('toast', type: 'success', message: 'Two-step sign-in is on.');
    }

    public function regenerateCodes(TwoFactor $twoFactor, Audit $audit): void
    {
        $user = auth()->user();
        abort_unless($user->hasTwoFactor(), 409);
        $this->checkPassword();

        [$plain, $hashes] = $twoFactor->recoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $hashes])->save();
        $audit->record('auth.recovery_codes_regenerated', $user);
        $this->freshCodes = $plain;
        $this->reset('password');
    }

    public function disable(Audit $audit): void
    {
        $user = auth()->user();
        abort_if($user->requiresTwoFactor(), 403, 'Two-step sign-in is required for your account.');
        $this->checkPassword();

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $audit->record('auth.two_factor_disabled', $user);
        $this->reset('password', 'freshCodes');
        $this->dispatch('toast', type: 'success', message: 'Two-step sign-in is off.');
    }

    public function signOutOthers(SignIn $signIn, Audit $audit): void
    {
        $this->checkPassword();
        $signIn->signOutEverywhere(auth()->user(), request());
        $audit->record('auth.signed_out_everywhere', auth()->user());
        $this->reset('password');
        $this->dispatch('toast', type: 'success', message: 'Signed out on all your other devices, including the mobile app.');
    }

    public function revokeToken(int $id, Audit $audit): void
    {
        $token = auth()->user()->tokens()->whereKey($id)->firstOrFail();
        $audit->record('auth.device_revoked', auth()->user(), new: ['device' => $token->name]);
        $token->delete();
        $this->dispatch('toast', type: 'success', message: "Signed out of {$token->name}.");
    }

    public function dismissCodes(): void
    {
        $this->freshCodes = [];
    }

    public function render(TwoFactor $twoFactor): View
    {
        $user = auth()->user();
        $secret = $this->secret();

        return view('livewire.account.security', [
            'user' => $user,
            'required' => $user->requiresTwoFactor(),
            'forced' => (bool) session('two_factor.setup_required') && ! $user->hasTwoFactor(),
            'qr' => $secret ? $twoFactor->qrSvg($user, $secret) : null,
            'secret' => $secret ? trim(chunk_split($secret, 4, ' ')) : null,
            'codesLeft' => count($user->two_factor_recovery_codes ?? []),
            'sessions' => $this->browserSessions(),
            'tokens' => $user->tokens()->latest('last_used_at')->latest('id')->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at']),
            'history' => AuditLog::query()->where('subject_type', 'User')->where('subject_id', $user->id)
                ->whereIn('action', ['auth.login', 'auth.login_failed', 'auth.login_blocked', 'auth.password_reset', 'auth.signed_out_everywhere'])
                ->latest('created_at')->latest('id')->limit(10)->get(['action', 'new_values', 'ip_address', 'user_agent', 'created_at']),
        ]);
    }

    private function secret(): ?string
    {
        try {
            return $this->pendingSecret ? Crypt::decryptString($this->pendingSecret) : null;
        } catch (DecryptException) {
            return null;
        }
    }

    private function checkPassword(): void
    {
        $this->resetValidation();
        if (! Hash::check($this->password, auth()->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Enter your current password to confirm.']);
        }
    }

    /** @return Collection<int, array{device: string, ip: ?string, last_active: Carbon, current: bool}> */
    private function browserSessions(): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        return DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
            ->where('user_id', auth()->id())->orderByDesc('last_activity')->limit(10)->get()
            ->map(fn (object $s) => [
                'device' => DeviceName::from($s->user_agent),
                'ip' => is_string($s->ip_address) ? $s->ip_address : null,
                'last_active' => Carbon::createFromTimestamp($s->last_activity),
                'current' => $s->id === session()->getId(),
            ]);
    }
}
