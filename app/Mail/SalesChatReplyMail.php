<?php

namespace App\Mail;

use App\Models\SalesChat;
use App\Models\SalesChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A reply from the SureHelp team to a visitor who has left the chat (D78). */
class SalesChatReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SalesChat $chat, public SalesChatMessage $reply) {}

    public function envelope(): Envelope
    {
        $from = config('sales.notify') ?: config('mail.contact_to');

        return new Envelope(subject: 'A reply from SureHelp', replyTo: array_filter([$from]));
    }

    public function content(): Content
    {
        return new Content(text: 'emails.sales-chat-reply', with: ['name' => $this->chat->lead['name'] ?? null, 'body' => $this->reply->body]);
    }
}
