<?php

namespace App\Mail;

use App\Models\SalesChat;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells the SureHelp team about a chat lead, a booked demo or a chat waiting for a person (D78). */
class SalesLeadAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SalesChat $chat, public string $headline) {}

    public function envelope(): Envelope
    {
        $who = $this->chat->lead['name'] ?? 'a website visitor';

        return new Envelope(
            subject: '[SureHelp chat] '.$this->headline.' ('.$who.')',
            replyTo: array_filter([$this->chat->lead['email'] ?? null]),
        );
    }

    public function content(): Content
    {
        return new Content(text: 'emails.sales-lead-alert', with: [
            'lead' => $this->chat->lead ?? [],
            'enquiry' => $this->chat->enquiry,
            'recent' => $this->chat->messages()->latest('id')->limit(8)->get()->reverse(),
            'link' => $this->chat->contact_submission_id ? route('admin.enquiries', ['enquiry' => $this->chat->contact_submission_id]) : route('admin.enquiries', ['show' => 'all']),
        ]);
    }
}
