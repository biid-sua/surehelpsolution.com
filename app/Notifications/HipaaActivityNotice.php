<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * In HIPAA mode (D117), instead of an alert with patient details by email, text or push: just that
 * there's something new, to read inside SureHelp.
 */
class HipaaActivityNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $channels */
    public function __construct(public readonly string $business, public readonly array $channels)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)->subject('New activity for '.$this->business)
            ->line('There\'s new activity for '.$this->business.' in SureHelp. For patient privacy, the details are only shown after you sign in.')
            ->action('Open SureHelp', route('app.dashboard'));
    }

    /** @return array{title: string, body: string, url: string} what push and text show */
    public function toArray(User $notifiable): array
    {
        return ['title' => 'New activity for '.$this->business, 'body' => 'Sign in to SureHelp to see it.', 'url' => route('app.dashboard', [], false)];
    }
}
