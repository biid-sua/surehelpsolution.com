<?php

namespace App\Mail;

use App\Models\ContactSubmission;
use App\Services\Sales\DemoFollowUp;
use App\Services\Sales\DemoSlots;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * The visitor's demo invitation (D78), with a calendar file. `moved` updates the same calendar entry,
 * and `cancelled` removes it (D96): one UID per demo, with a rising SEQUENCE.
 */
class SalesDemoBookedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ContactSubmission $enquiry, public string $kind = 'booked') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->kind) {
            'moved' => 'Your SureHelp demo has moved: '.$this->label(),
            'cancelled' => 'Your SureHelp demo is cancelled',
            default => 'Your SureHelp demo: '.$this->label(),
        });
    }

    public function content(): Content
    {
        $demo = app(DemoFollowUp::class);

        return new Content(text: 'emails.sales-demo-booked', with: [
            'kind' => $this->kind,
            'label' => $this->label(),
            'location' => $demo->where(),
            'meetingUrl' => $demo->meetingUrl(),
            'manageUrl' => $demo->manageUrl($this->enquiry),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $method = $this->kind === 'cancelled' ? 'CANCEL' : 'REQUEST';

        return [Attachment::fromData(fn () => $this->ics(), 'surehelp-demo.ics')->withMime("text/calendar; charset=UTF-8; method={$method}")];
    }

    public function ics(): string
    {
        $demo = app(DemoFollowUp::class);
        $cancelled = $this->kind === 'cancelled';
        $start = CarbonImmutable::parse($this->enquiry->demo_at)->utc();
        $end = $start->addMinutes((int) config('sales.demos.minutes', 30));
        $escape = fn (string $v) => addcslashes(str_replace(["\r\n", "\n"], '\\n', $v), ',;\\');
        $organizer = (string) (config('sales.notify') ?: config('mail.contact_to', config('company.email')));
        // Each change has to rank above the last for calendars to update the entry: minutes since the enquiry.
        $sequence = max(0, (int) floor(($this->enquiry->demo_booked_at ?? now())->diffInMinutes($this->enquiry->created_at, true)));
        $details = 'A 30-minute walkthrough of SureHelp with our team. '.$demo->where().' Need another time? '.$demo->manageUrl($this->enquiry);

        return implode("\r\n", array_filter([
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//SureHelp Solutions//Sales demo//EN', 'METHOD:'.($cancelled ? 'CANCEL' : 'REQUEST'), 'BEGIN:VEVENT',
            'UID:demo-'.$this->enquiry->id.'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'surehelp'),
            'SEQUENCE:'.$sequence,
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$start->format('Ymd\THis\Z'),
            'DTEND:'.$end->format('Ymd\THis\Z'),
            'SUMMARY:'.$escape('SureHelp demo'),
            'DESCRIPTION:'.$escape($details),
            'LOCATION:'.$escape($demo->meetingUrl() ? Str::before($demo->where(), ': ') : (string) config('sales.demos.location')),
            $demo->meetingUrl() ? 'URL:'.$demo->meetingUrl() : null,
            $cancelled ? 'STATUS:CANCELLED' : 'STATUS:CONFIRMED',
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
