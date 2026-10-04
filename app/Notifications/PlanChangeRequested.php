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
 * To the SureHelp billing team: a business asked to start or change its plan.
 */
class PlanChangeRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Organization $organization, public readonly Plan $plan, public readonly User $requestedBy)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => 'plan.change_requested',
            'title' => $this->organization->name.' asks for the '.$this->plan->name.' plan',
            'body' => 'Requested by '.$this->requestedBy->name.' ('.$this->requestedBy->email.').',
            'url' => route('admin.billing', ['tab' => 'subscriptions'], absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Plan request: '.$this->organization->name.' → '.$this->plan->name)
            ->line($this->requestedBy->name.' ('.$this->requestedBy->email.') asked to move '.$this->organization->name.' to '.$this->plan->name.' ('.$this->plan->priceLabel().').')
            ->action('Open subscriptions', route('admin.billing', ['tab' => 'subscriptions']));
    }
}
