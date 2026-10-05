<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To SureHelp staff: a business finished the setup wizard and is ready for its go-live check.
 */
class SetupCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Organization $organization)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->organization->name} finished setup")
            ->line("{$this->organization->name} finished the setup wizard. Check their details, assign agents and connect their phone line to go live.")
            ->action('Open the business', route('admin.organizations.show', $this->organization));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => "{$this->organization->name} finished setup",
            'body' => 'Check their details, assign agents and connect their phone line.',
            'url' => route('admin.organizations.show', $this->organization, absolute: false),
        ];
    }
}
