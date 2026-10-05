<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Friendly overdue reminder (payment.failed: "payment overdue" while payments are manual).
 */
class InvoiceOverdue extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

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
            'title' => 'Invoice '.$this->invoice->number.' is overdue',
            'body' => $this->invoice->money($this->invoice->balanceCents()).' was due '.$this->invoice->due_at->format('M j').'.',
            'organization_id' => $this->invoice->organization_id,
            'url' => route('app.billing.invoice', $this->invoice, absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reminder: invoice '.$this->invoice->number.' is overdue')
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line('Invoice '.$this->invoice->number.' for '.$this->invoice->money($this->invoice->balanceCents()).' was due on '.$this->invoice->due_at->format('M j, Y').'.')
            ->line('If you\'ve already paid, thank you, and please let us know with the "I\'ve paid" button so we can match it.')
            ->action('Pay now', route('app.billing.invoice', $this->invoice));
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::PaymentFailed->value;
    }
}
