<?php

namespace App\Notifications;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells the owner their SureHelp service went live, was paused, cancelled or resumed (D45).
 */
class ServiceStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Organization $organization, public readonly OrganizationStatus $from)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => 'service.status',
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => route('app.dashboard', absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line($this->body());
        if ($this->organization->status_reason) {
            $mail->line('Reason: '.$this->organization->status_reason);
        }

        return $mail->action('Open SureHelp', route('app.dashboard'))
            ->line('Questions? Reply to this email and our team will help.');
    }

    public function databaseType(object $notifiable): string
    {
        return 'service.status';
    }

    private function title(): string
    {
        $name = $this->organization->name;

        return match ($this->organization->status) {
            OrganizationStatus::Active => $this->from === OrganizationStatus::Onboarding ? "{$name} is live on SureHelp" : "SureHelp service for {$name} has resumed",
            OrganizationStatus::Paused => "SureHelp service for {$name} is paused",
            OrganizationStatus::Cancelled => "SureHelp service for {$name} has been cancelled",
            OrganizationStatus::Onboarding => "{$name} is being set up",
        };
    }

    private function body(): string
    {
        return match ($this->organization->status) {
            OrganizationStatus::Active => $this->from === OrganizationStatus::Onboarding
                ? 'Our receptionists now answer your calls, and your website tools are on.'
                : 'Our receptionists answer your calls again, and your website tools are back on.',
            OrganizationStatus::Paused => 'Our receptionists aren\'t answering for you right now and your website tools are hidden. Your data is safe and you can still sign in.',
            OrganizationStatus::Cancelled => 'Our receptionists no longer answer for you and your website tools are off. You can still sign in to download your data.',
            OrganizationStatus::Onboarding => 'We\'re getting your service ready.',
        };
    }
}
