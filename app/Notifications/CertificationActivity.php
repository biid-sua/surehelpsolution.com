<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\TrainingCertificate;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * "Your Customer Communication certification expires in 30 days." and "… has expired" (brief
 * §1.12, §10). Each is sent once per certificate.
 */
class CertificationActivity extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public const EXPIRING = 'expiring';

    public const EXPIRED = 'expired';

    public int $tries = 3;

    public function __construct(public readonly TrainingCertificate $certificate, public readonly string $change)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::CertificationExpiring);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::CertificationExpiring->value,
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => route('agent.university.course', $this->certificate->course->ulid, false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->body())
            ->action('Renew it now', route('agent.university.course', $this->certificate->course->ulid));
    }

    private function title(): string
    {
        $days = max(1, (int) ceil(now()->diffInDays($this->certificate->expires_at, false)));

        return $this->change === self::EXPIRED
            ? 'Your '.$this->certificate->name.' certification has expired'
            : 'Your '.$this->certificate->name.' certification expires in '.$days.' '.Str::plural('day', $days);
    }

    private function body(): string
    {
        $date = $this->certificate->expires_at?->setTimezone(config('app.timezone'))->format('j M Y');

        return $this->change === self::EXPIRED
            ? 'It expired on '.$date.'. Take "'.$this->certificate->course->title.'" again to recertify.'
            : 'It\'s valid until '.$date.'. Take "'.$this->certificate->course->title.'" again before then to renew it.';
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::CertificationExpiring->value;
    }
}
