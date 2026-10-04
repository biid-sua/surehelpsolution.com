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
        $labels = [
            'general' => 'General Inquiry',
            'sales' => 'Sales',
            'demo' => 'Demo Request',
            'support' => 'Support',
            'enterprise' => 'Enterprise',
        ];

        $type = $labels[$this->submission->inquiry_type] ?? 'Contact';

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
