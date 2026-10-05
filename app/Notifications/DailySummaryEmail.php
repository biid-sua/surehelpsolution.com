<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The daily summary email (spec NTF-04), sent at the time each person chose.
 */
class DailySummaryEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<string, mixed>  $summary from DailySummary::for() */
    public function __construct(public readonly Organization $organization, public readonly array $summary) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->summary;
        $plural = fn (int $n, string $word) => number_format($n).' '.Str::plural($word, $n);

        $mail = (new MailMessage)
            ->subject($this->organization->name.': your day at a glance')
            ->greeting('Good morning, '.Str::before(trim($notifiable->name).' ', ' ').'.')
            ->line("**Yesterday ({$s['date']}):** ".$plural($s['calls'], 'call').' answered, '.$plural($s['booked'], 'job').' booked, '
                .$plural($s['leads'], 'new lead').($s['missed'] ? ', '.$plural($s['missed'], 'missed call') : '').'.');

        if ($s['appointment_count'] > 0) {
            $mail->line('**Today:** '.$plural($s['appointment_count'], 'appointment').': '
                .collect($s['appointments'])->map(fn (array $a) => $a['time'].' '.$a['title'])->join('; ')
                .($s['appointment_count'] > count($s['appointments']) ? '; and more' : '').'.');
        } else {
            $mail->line('**Today:** no appointments booked yet.');
        }

        $waiting = array_filter([
            $s['escalations'] ? $plural($s['escalations'], 'open escalation') : null,
            $s['follow_ups'] ? $plural($s['follow_ups'], 'call-back').' waiting'.($s['overdue'] ? " ({$s['overdue']} overdue)" : '') : null,
        ]);
        if ($waiting) {
            $mail->line('**Waiting for you:** '.implode(' · ', $waiting).'.');
        }

        return $mail->action('Open your dashboard', route('app.dashboard'))
            ->line('Change the time or turn this email off under Notifications.');
    }
}
