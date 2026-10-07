<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A business's automation telling its team something (D50, "notify team" action).
 */
class AutomationNotice extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    public function __construct(
        public readonly Organization $organization,
        public readonly string $title,
        public readonly string $text,
        public readonly ?string $customerUlid = null,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::AutomationNotice);
    }

    private function url(bool $absolute = true): string
    {
        return $this->customerUlid ? route('app.customers.show', $this->customerUlid, $absolute) : route('app.dashboard', [], $absolute);
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::AutomationNotice->value,
            'title' => $this->title,
            'body' => $this->text,
            'organization_id' => $this->organization->id,
            'url' => $this->url(false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title.' · '.$this->organization->name)
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->text)
            ->action($this->customerUlid ? 'Open the customer' : 'Open SureHelp', $this->url());
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::AutomationNotice->value;
    }
}
