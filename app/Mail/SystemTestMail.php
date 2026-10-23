<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Can this server send email?" from launch:check and Admin › System status (D79). */
class SystemTestMail extends Mailable
{
    use Queueable;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'SureHelp test email');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>This is a test email from '.e(config('app.name')).' ('.e(config('app.url')).'). If you can read it, sending works.</p>');
    }
}
