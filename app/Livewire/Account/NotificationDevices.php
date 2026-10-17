<?php

namespace App\Livewire\Account;

use App\Exceptions\TelephonyUnavailable;
use App\Models\FcmToken;
use App\Models\PhoneNumber;
use App\Services\Account\NotificationPhone;
use App\Support\NotificationChannels;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Where texts and push notifications reach this person (D63): their verified mobile number and the
 * phones signed in to the SureHelp app. Shown on both notification settings pages.
 */
class NotificationDevices extends Component
{
    public string $phone = '';

    public string $code = '';

    public bool $codeSent = false;

    public function sendCode(NotificationPhone $phones): void
    {
        $this->validate(['phone' => ['required', 'string', 'max:30']], [], ['phone' => 'mobile number']);
        try {
            $phones->sendCode(auth()->user(), $this->phone);
        } catch (TelephonyUnavailable $e) {
            $this->addError('phone', $e->getMessage());

            return;
        }
        $this->codeSent = true;
        $this->code = '';
        $this->dispatch('toast', type: 'success', message: 'We texted you a code.');
    }

    public function verify(NotificationPhone $phones): void
    {
        $this->validate(['code' => ['required', 'string', 'max:10']]);
        if (! $phones->verify(auth()->user(), $this->code)) {
            $this->addError('code', 'That code isn\'t right or has expired. Send a new one if needed.');

            return;
        }
        $this->reset(['phone', 'code', 'codeSent']);
        $this->dispatch('toast', type: 'success', message: 'Mobile number verified. Choose which notifications to get by text below.');
    }

    public function removePhone(NotificationPhone $phones): void
    {
        $phones->remove(auth()->user());
        $this->reset(['phone', 'code', 'codeSent']);
    }

    public function forgetDevice(int $id): void
    {
        FcmToken::query()->where('user_id', auth()->id())->whereKey($id)->delete();
    }

    public function render(): View
    {
        $user = auth()->user()->fresh();

        return view('livewire.account.notification-devices', [
            'smsReady' => NotificationChannels::enabled('sms'),
            'pushReady' => NotificationChannels::enabled('push'),
            'verified' => $user->sms_phone_verified_at ? PhoneNumber::pretty($user->sms_phone) : null,
            'devices' => FcmToken::query()->where('user_id', $user->id)->latest('last_used_at')->get(),
        ]);
    }
}
