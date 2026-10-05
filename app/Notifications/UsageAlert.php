<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Concerns\RespectsQuietHours;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The business has used 80% or 100% of the calls its plan includes this period (task.md BIL-11).
 * Calls keep being answered either way.
 */
class UsageAlert extends Notification implements ShouldQueue
{
    use Queueable, RespectsQuietHours;

    public int $tries = 3;

    /**
     * @param  array{used: int, included: int|null, unit_cents: int, until: CarbonImmutable}  $meter
     */
    public function __construct(public readonly Subscription $subscription, public readonly int $level, public readonly array $meter)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::UsageAlert);
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::UsageAlert->value,
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => route('app.billing', absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->body())
            ->action('See your usage', route('app.billing'));
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::UsageAlert->value;
    }

    private function title(): string
    {
        return $this->level >= 100 ? 'You\'ve used all the calls in your plan' : "You've used {$this->level}% of the calls in your plan";
    }

    private function body(): string
    {
        $until = $this->meter['until']->subDay()->format('M j');
        $text = "{$this->meter['used']} of {$this->meter['included']} calls answered this period (until {$until}).";

        return $text.' We keep answering every call'.($this->meter['unit_cents'] > 0
            ? '; extra calls are '.Money::format($this->meter['unit_cents'], $this->subscription->currency).' each on your next invoice.'
            : '.').' Ask us about a bigger plan if this happens often.';
    }
}
