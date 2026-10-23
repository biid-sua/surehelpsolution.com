<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A customer paid the business through a payment link (D85).
 */
class CustomerPaid extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    public function __construct(public readonly PaymentRequest $payment)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::CustomerPaid);
    }

    private function title(): string
    {
        return ($this->payment->customer?->fullName() ?: 'A customer').' paid '.$this->payment->amount();
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::CustomerPaid->value,
            'title' => $this->title(),
            'body' => $this->payment->description,
            'organization_id' => $this->payment->organization_id,
            'url' => route('app.payments', [], false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->title().' for "'.$this->payment->description.'".')
            ->line('The money goes to your Stripe account and reaches your bank on your usual Stripe payout schedule.')
            ->action('See payments', route('app.payments'));
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::CustomerPaid->value;
    }
}
