<?php

namespace App\Mail;

use App\Models\ContactSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactFormMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ContactSubmission $submission) {}

    public function envelope(): Envelope
    {
        $type = $this->submission->inquiryLabel();

        return new Envelope(
            subject: "[SureHelp] {$type} from {$this->submission->name}",
            replyTo: [$this->submission->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.contact-form',
        );
    }
}
