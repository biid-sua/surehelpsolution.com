<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Confirms to the owner that their account will close, and how to stop it (CMP-07).
 */
class AccountClosureScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Organization $organization) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $date = $this->organization->closes_at?->setTimezone($this->organization->timezoneOrDefault())->format('l, F j, Y');

        return (new MailMessage)
            ->subject('Your SureHelp account will close on '.$date)
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line("You asked us to close the account for {$this->organization->name}. We'll keep answering your calls until {$date}.")
            ->line('On that day your customers, calls, appointments and settings are deleted for good, and your team can no longer sign in. Invoices are kept, as the law requires.')
            ->line('Download a copy of your data before then if you need it. Changed your mind? Keep your account with one click:')
            ->action('Keep my account', route('app.settings.privacy'))
            ->line('If you didn\'t ask for this, keep your account and change your password straight away.');
    }
}
