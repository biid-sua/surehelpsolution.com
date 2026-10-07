<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome email for someone SureHelp staff added (D51): a link to choose their own password,
 * valid for a week, so nobody has to pass a password around.
 */
class WelcomeToSureHelp extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token, public readonly ?string $businessName = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to SureHelp')
            ->greeting('Hi '.$notifiable->name.',')
            ->line($this->businessName
                ? "Your SureHelp account for {$this->businessName} is ready. Choose a password to sign in:"
                : 'Your SureHelp account is ready. Choose a password to sign in:')
            ->action('Choose your password', route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]))
            ->line('The link works once and expires in 7 days. After that, use "Forgot password" on the sign-in page.')
            ->line('Sign in at '.route('login').' with '.$notifiable->getEmailForPasswordReset().'.');
    }
}
