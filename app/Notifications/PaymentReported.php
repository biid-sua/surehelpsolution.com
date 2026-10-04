<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the SureHelp billing team: a client says they paid; confirm it in Payoneer and record it.
 */
class PaymentReported extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly Invoice $invoice, public readonly User $reportedBy)
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
            'event' => 'payment.reported',
            'title' => ($this->invoice->organization->name ?? 'A business').' reports paying '.$this->invoice->number,
            'body' => $this->invoice->client_payment_note ?: 'Check Payoneer and record the payment.',
            'url' => route('admin.billing', ['invoice' => $this->invoice->ulid], absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Payment reported: '.$this->invoice->number.' · '.($this->invoice->organization->name ?? ''))
            ->line($this->reportedBy->name.' says invoice '.$this->invoice->number.' ('.$this->invoice->money($this->invoice->balanceCents()).') has been paid.')
            ->line('Their note: '.($this->invoice->client_payment_note ?: '—'))
            ->line('Check your Payoneer account, then record the payment so their account shows it as paid.')
            ->action('Record payment', route('admin.billing', ['invoice' => $this->invoice->ulid]));
    }
}
