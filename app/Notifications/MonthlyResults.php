<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use App\Services\Metrics\ResultsPdf;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * "This month we answered X calls and booked $Y in jobs for you" (spec RPT-02), with the PDF.
 */
class MonthlyResults extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    /**
     * @param  array{answered: int, booked: int, leads: int, after_hours: int|null, revenue_cents: int|null}  $summary
     */
    public function __construct(public readonly Organization $organization, public readonly string $month, public readonly string $label, public readonly array $summary)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::MonthlyReport);
    }

    public function headline(): string
    {
        $s = $this->summary;
        $line = 'In '.$this->label.' we answered '.number_format($s['answered']).' '.Str::plural('call', $s['answered']);

        return $line.($s['revenue_cents'] !== null
            ? ' and booked '.Money::format($s['revenue_cents'], $this->organization->currency).' in jobs for you.'
            : ' and booked '.number_format($s['booked']).' '.Str::plural('job', $s['booked']).' for you.');
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::MonthlyReport->value,
            'title' => 'Your '.$this->label.' results',
            'body' => $this->headline(),
            'organization_id' => $this->organization->id,
            'url' => route('app.results', ['month' => $this->month], absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $pdf = app(ResultsPdf::class);
        $s = $this->summary;
        $mail = (new MailMessage)
            ->subject($this->organization->name.': your '.$this->label.' results')
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->headline())
            ->line('New leads: '.number_format($s['leads']).($s['after_hours'] !== null ? ' · After-hours calls caught: '.number_format($s['after_hours']) : '').'.');

        return $mail->action('See your results', route('app.results', ['month' => $this->month]))
            ->line('The full report is attached.')
            ->attachData($pdf->render($this->organization, $this->month), $pdf->filename($this->organization, $this->month), ['mime' => 'application/pdf']);
    }
}
