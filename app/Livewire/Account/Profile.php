<?php

namespace App\Livewire\Account;

use App\Services\Account\SignIn;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Your own details and password (spec AUTH-06). Changing the password signs you out everywhere else.
 */
#[Layout('layouts.portal')]
#[Title('Your profile')]
class Profile extends Component
{
    public string $name = '';

    public string $phone = '';

    public string $timezone = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = $user->name;
        $this->phone = (string) $user->phone;
        $this->timezone = (string) $user->timezone;
    }

    public function saveDetails(): void
    {
        $this->resetValidation();
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'timezone' => ['nullable', Rule::in(\DateTimeZone::listIdentifiers())],
        ]);

        auth()->user()->update(['name' => trim($data['name']), 'phone' => trim((string) $data['phone']) ?: null]);
        auth()->user()->forceFill(['timezone' => $data['timezone'] ?: null])->save();
        $this->dispatch('toast', type: 'success', message: 'Your details are saved.');
    }

    public function changePassword(SignIn $signIn): void
    {
        $this->resetValidation();
        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'confirmed', Password::min(8), 'different:currentPassword'],
        ], ['newPassword.different' => 'Choose a password different from your current one.'], ['currentPassword' => 'current password', 'newPassword' => 'new password']);

        $user = auth()->user();
        if (! Hash::check($this->currentPassword, $user->password)) {
            throw ValidationException::withMessages(['currentPassword' => 'That isn\'t your current password.']);
        }

        $user->forceFill(['password' => $this->newPassword, 'must_change_password' => false])->save();
        $signIn->signOutEverywhere($user, request());
        $this->reset('currentPassword', 'newPassword', 'newPassword_confirmation');
        $this->dispatch('toast', type: 'success', message: 'Password changed. You\'re signed out on your other devices.');
    }

    public function render(): View
    {
        return view('livewire.account.profile', [
            'user' => auth()->user(),
            'allTimezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }
}
