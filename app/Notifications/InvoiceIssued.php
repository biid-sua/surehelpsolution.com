<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoicePdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A new invoice, with the PDF attached and the ways to pay (invoice.issued).
 */
class InvoiceIssued extends Notification implements ShouldQueue
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
        return $notifiable->notificationChannelsFor(NotificationEvent::InvoiceIssued);
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::InvoiceIssued->value,
            'title' => 'Invoice '.$this->invoice->number.' · '.$this->invoice->money($this->invoice->total_cents),
            'body' => 'Due '.$this->invoice->due_at->format('M j, Y').'.',
            'organization_id' => $this->invoice->organization_id,
            'url' => route('app.billing.invoice', $this->invoice, absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $pdf = app(InvoicePdf::class);

        return (new MailMessage)
            ->subject('Invoice '.$this->invoice->number.' from '.config('app.name').' · '.$this->invoice->money($this->invoice->total_cents))
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line('Your invoice '.$this->invoice->number.($this->invoice->periodLabel() ? ' for '.$this->invoice->periodLabel() : '').' is ready: '.$this->invoice->money($this->invoice->total_cents).', due '.$this->invoice->due_at->format('M j, Y').'.')
            ->line('You can pay by card or from your bank account. The invoice (attached) shows how.')
            ->action('View and pay', route('app.billing.invoice', $this->invoice))
            ->attachData($pdf->render($this->invoice), $pdf->filename($this->invoice), ['mime' => 'application/pdf']);
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::InvoiceIssued->value;
    }
}
