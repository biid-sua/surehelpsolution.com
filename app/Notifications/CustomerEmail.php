<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An email to a business's customer, sent in the business's name; replies go to the business.
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
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->from((string) config('mail.from.address'), $this->fromName)
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
            $mail->replyTo($this->replyTo, $this->fromName);
        }

        return $mail;
    }
}
