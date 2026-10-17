<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To SureHelp staff: a business signed up on the website (D65) and is waiting to be set up and go live.
 */
class NewSignup extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Organization $organization, public readonly User $owner, public readonly ?Plan $plan)
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
            ->subject("New sign-up: {$this->organization->name}")
            ->line("{$this->owner->name} ({$this->owner->email}) signed up {$this->organization->name} on the website{$this->planText()}.")
            ->line('The business is onboarding. Check their details, assign agents and connect their phone line to go live.')
            ->action('Open the business', route('admin.organizations.show', $this->organization));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => "New sign-up: {$this->organization->name}",
            'body' => "{$this->owner->name} signed up on the website{$this->planText()}.",
            'url' => route('admin.organizations.show', $this->organization, absolute: false),
            'event' => 'organization.signed_up',
        ];
    }

    private function planText(): string
    {
        return $this->plan ? ' for the '.$this->plan->name.' plan' : '';
    }
}
