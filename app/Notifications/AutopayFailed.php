<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** The saved card couldn't pay a new invoice (D64). Follows the member's payment.failed choices. */
class AutopayFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly Invoice $invoice)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::PaymentFailed);
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::PaymentFailed->value,
            'title' => 'Automatic payment didn\'t go through',
            'body' => 'Invoice '.$this->invoice->number.' ('.$this->invoice->money($this->invoice->balanceCents()).'): '.$this->invoice->autopay_error,
            'organization_id' => $this->invoice->organization_id,
            'url' => route('app.billing', absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Automatic payment didn\'t go through · '.$this->invoice->number)
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line('We tried to charge your saved card for invoice '.$this->invoice->number.' ('.$this->invoice->money($this->invoice->balanceCents()).'), but it didn\'t go through.')
            ->line('Reason: '.$this->invoice->autopay_error)
            ->action('Pay on your Billing page', route('app.billing'))
            ->line('Your service isn\'t affected. The invoice stays open until it\'s paid.');
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::PaymentFailed->value;
    }
}
