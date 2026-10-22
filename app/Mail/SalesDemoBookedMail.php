<?php

namespace App\Mail;

use App\Models\ContactSubmission;
use App\Services\Sales\DemoSlots;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The visitor's invitation to a demo booked in the website chat (D78), with a calendar file. */
class SalesDemoBookedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ContactSubmission $enquiry) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your SureHelp demo: '.$this->label());
    }

    public function content(): Content
    {
        return new Content(text: 'emails.sales-demo-booked', with: ['label' => $this->label(), 'location' => config('sales.demos.location')]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->ics(), 'surehelp-demo.ics')->withMime('text/calendar; charset=UTF-8; method=REQUEST')];
    }

    public function ics(): string
    {
        $start = CarbonImmutable::parse($this->enquiry->demo_at)->utc();
        $end = $start->addMinutes((int) config('sales.demos.minutes', 30));
        $escape = fn (string $v) => addcslashes(str_replace(["\r\n", "\n"], '\\n', $v), ',;\\');
        $organizer = (string) (config('sales.notify') ?: config('mail.contact_to', config('company.email')));

        return implode("\r\n", array_filter([
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//SureHelp Solutions//Sales demo//EN', 'METHOD:REQUEST', 'BEGIN:VEVENT',
            'UID:demo-'.$this->enquiry->id.'-'.$start->format('YmdHis').'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'surehelp'),
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$start->format('Ymd\THis\Z'),
            'DTEND:'.$end->format('Ymd\THis\Z'),
            'SUMMARY:'.$escape('SureHelp demo'),
            'DESCRIPTION:'.$escape('A 30-minute walkthrough of SureHelp with our team. '.config('sales.demos.location')),
            'LOCATION:'.$escape((string) config('sales.demos.location')),
            $organizer ? 'ORGANIZER;CN=SureHelp Solutions:mailto:'.$organizer : null,
            'ATTENDEE;CN='.$escape($this->enquiry->name).';RSVP=TRUE:mailto:'.$this->enquiry->email,
            'END:VEVENT', 'END:VCALENDAR', '',
        ]));
    }

    private function label(): string
    {
        return app(DemoSlots::class)->label(CarbonImmutable::parse($this->enquiry->demo_at));
    }
}
