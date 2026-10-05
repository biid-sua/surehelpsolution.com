<?php

namespace App\Notifications\Account;

use App\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation to join a business's team on SureHelp. The token is only ever in this email.
 */
class TeamInvitation extends Notification
{
    use Queueable;

    public function __construct(public readonly OrganizationInvitation $invitation, public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $business = $this->invitation->organization->name;
        $inviter = $this->invitation->inviter()->value('name') ?? 'The business owner';

        return (new MailMessage)
            ->subject("Join {$business} on SureHelp")
            ->greeting('Hello,')
            ->line("{$inviter} invited you to join {$business} on SureHelp as ".mb_strtolower($this->invitation->roleLabel()).'.')
            ->line("You'll see the calls, appointments and messages our receptionists handle for {$business}.")
            ->action('Accept invitation', route('invitations.show', $this->token))
            ->line('The invitation expires on '.$this->invitation->expires_at->format('M j, Y').'. If you weren\'t expecting it, you can ignore this email.');
    }
}
