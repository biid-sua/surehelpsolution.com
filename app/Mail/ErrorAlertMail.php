<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A server error, for the team (D91). Sent straight away, not queued: the queue may be what's broken.
 */
class ErrorAlertMail extends Mailable
{
    use Queueable;

    /** @param list<string> $trace */
    public function __construct(
        public readonly string $class,
        public readonly string $message,
        public readonly string $where,
        public readonly string $url,
        public readonly int|string|null $userId,
        public readonly array $trace,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[SureHelp error] '.class_basename($this->class).': '.mb_substr($this->message, 0, 80));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.error-alert');
    }
}
