<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Receipt for a payment (payment.received).
 */
class PaymentReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly Payment $payment)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::PaymentReceived);
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::PaymentReceived->value,
            'title' => 'Payment received · '.$this->payment->amountLabel(),
            'body' => 'Thank you. Applied to invoice '.($this->payment->invoice->number ?? '').'.',
            'organization_id' => $this->payment->organization_id,
            'url' => route('app.billing', absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $invoice = $this->payment->invoice;

        return (new MailMessage)
            ->subject('Payment received · '.$this->payment->amountLabel())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line('We received '.$this->payment->amountLabel().' on '.$this->payment->received_at->format('M j, Y').' ('.$this->payment->method->label().')'.($invoice ? ' for invoice '.$invoice->number.'.' : '.'))
            ->line($invoice && $invoice->balanceCents() > 0 ? 'Remaining balance: '.$invoice->money($invoice->balanceCents()).'.' : 'Your invoice is fully paid. Thank you!')
            ->action('View billing', route('app.billing'));
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::PaymentReceived->value;
    }
}
