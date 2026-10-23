<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Services\Business\Branding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An email to a business's customer, sent in the business's name; replies go to the business. With the
 * organization, it uses the business's branded layout (D82).
 */
class CustomerEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly string $fromName,
        public readonly ?string $replyTo = null,
        public readonly ?string $actionUrl = null,
        public readonly string $actionText = 'Change or cancel',
        public readonly ?int $organizationId = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The business's own look (D82): its logo always; with white-label, its colour and sender name too.
        $organization = $this->organizationId ? Organization::query()->find($this->organizationId) : null;
        $brand = $organization ? app(Branding::class)->for($organization) : null;
        $fromName = $brand['sender'] ?? $this->fromName;
        $mail = (new MailMessage)
            ->from((string) config('mail.from.address'), $fromName)
            ->subject($this->subject)
            ->greeting(' ')
            ->salutation(' ');
        foreach (preg_split('/\n{2,}/', $this->body) ?: [] as $paragraph) {
            $mail->line(trim($paragraph));
        }
        if ($this->actionUrl) {
            $mail->action($this->actionText, $this->actionUrl);
        }
        if ($this->replyTo) {
            $mail->replyTo($this->replyTo, $fromName);
        }
        if ($brand) {
            $mail->view(['html' => 'emails.customer', 'text' => 'emails.customer-text'], ['brand' => $brand, 'subjectLine' => $this->subject]);
        }

        return $mail;
    }
}
