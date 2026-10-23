<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A business someone referred paid its first invoice: a month's credit (D90).
 */
class ReferralRewarded extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    public function __construct(public readonly Referral $referral)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::SubscriptionUpdated);
    }

    private function title(): string
    {
        return 'You earned '.Money::format($this->referral->credit_cents).' credit';
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::SubscriptionUpdated->value,
            'title' => $this->title(),
            'body' => $this->referral->referred->name.' joined SureHelp with your link. Thank you!',
            'organization_id' => $this->referral->referrer_organization_id,
            'url' => route('app.billing', [], false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->referral->referred->name.' joined SureHelp with your referral link and paid their first invoice.')
            ->line('As a thank-you, '.Money::format($this->referral->credit_cents).' comes off your next invoices automatically.')
            ->action('See billing', route('app.billing'));
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::SubscriptionUpdated->value;
    }
}
