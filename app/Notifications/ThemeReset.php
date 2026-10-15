<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells someone that SureHelp reset the colour theme they had chosen, and why (D59). Personal
 * choices are only ever changed through this explicit, audited reset.
 */
class ThemeReset extends Notification
{
    use Queueable;

    public function __construct(public readonly string $reason, public readonly string $defaultLabel) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => 'ui.theme_reset',
            'title' => 'Your colour theme was reset',
            'body' => "SureHelp reset your colour theme to the default ({$this->defaultLabel}). Reason: {$this->reason}. You can pick your own again any time under Your account › Appearance.",
            'url' => route('account.profile', absolute: false).'#appearance',
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'ui.theme_reset';
    }
}
