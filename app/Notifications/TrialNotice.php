<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A self-serve free trial is about to end, or has ended without a plan (D97).
 */
class TrialNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param  'ending'|'ended'  $kind */
    public function __construct(public readonly Subscription $subscription, public readonly string $kind, public readonly int $daysLeft = 0)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $notifiable->notificationChannelsFor(NotificationEvent::SubscriptionUpdated);
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => NotificationEvent::SubscriptionUpdated->value,
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
            ->action($this->kind === 'ended' ? 'Choose a plan' : 'Keep my plan', route('app.billing'))
            ->line('Questions? Just reply to this email.');
    }

    public function databaseType(object $notifiable): string
    {
        return NotificationEvent::SubscriptionUpdated->value;
    }

    private function title(): string
    {
        return match (true) {
            $this->kind === 'ended' => 'Your SureHelp free trial has ended',
            $this->daysLeft === 1 => 'Your SureHelp free trial ends tomorrow',
            default => "Your SureHelp free trial ends in {$this->daysLeft} days",
        };
    }

    private function body(): string
    {
        $plan = $this->subscription->plan->name;

        return $this->kind === 'ended'
            ? 'Your account is now read-only. Nothing has been deleted: your customers, calls and bookings are all still there. Choose a plan to switch everything back on, including our receptionists.'
            : "To keep our receptionists answering and everything you've set up working, keep your {$plan} plan ({$this->subscription->priceLabel()}). You won't be charged until the trial ends.";
    }
}
