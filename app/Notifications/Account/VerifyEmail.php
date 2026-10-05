<?php

namespace App\Notifications\Account;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * "Confirm your email address": a signed link that expires after a day.
 */
class VerifyEmail extends Notification
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('verification.verify', now()->addDay(), [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ]);

        return (new MailMessage)
            ->subject('Confirm your email address')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Please confirm this is your email address, so we can reach you about your account and reset your password if you ever need to.')
            ->action('Confirm email address', $url)
            ->line('The link expires in 24 hours.');
    }
}
