<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Reset your password" email. Sent straight away (not queued): someone is waiting for it.
 */
class ResetPassword extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your SureHelp password')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Someone asked to reset the password for your SureHelp account. If it was you, choose a new password here:')
            ->action('Choose a new password', route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]))
            ->line("The link works once and expires in {$minutes} minutes.")
            ->line("If you didn't ask for this, you can ignore this email; your password stays the same.");
    }
}
