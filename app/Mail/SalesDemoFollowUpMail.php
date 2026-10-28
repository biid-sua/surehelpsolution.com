<?php

namespace App\Mail;

use App\Models\ContactSubmission;
use App\Services\Sales\DemoFollowUp;
use App\Services\Sales\DemoSlots;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Emails around a demo call (D96): reminders the day and hour before, a recap after it, a "sorry we
 * missed you" after a no-show, a nudge a few days later, and the team's alert when a visitor moves or
 * cancels one.
 */
class SalesDemoFollowUpMail extends Mailable
{
    use Queueable, SerializesModels;

    public const KINDS = ['reminder_day', 'reminder_hour', 'recap', 'missed', 'nudge', 'team'];

    public function __construct(public ContactSubmission $enquiry, public string $kind, public ?string $headline = null) {}

    public function envelope(): Envelope
    {
        $label = $this->label();

        return new Envelope(subject: match ($this->kind) {
            'reminder_day' => 'Tomorrow: your SureHelp demo, '.$label,
            'reminder_hour' => 'Starting soon: your SureHelp demo at '.CarbonImmutable::parse($this->enquiry->demo_at)->setTimezone(app(DemoSlots::class)->timezone())->format('g:i A T'),
            'recap' => 'Thanks for your time: your next step with SureHelp',
            'missed' => 'Sorry we missed you: pick a new time',
            'nudge' => 'Ready to stop missing calls?',
            default => '[SureHelp demo] '.$this->headline,
        });
    }

    public function content(): Content
    {
        $demo = app(DemoFollowUp::class);

        return new Content(text: 'emails.sales-demo-follow-up', with: [
            'first' => Str::before(trim($this->enquiry->name).' ', ' '),
            'label' => $this->label(),
            'location' => $demo->where(),
            'meetingUrl' => $demo->meetingUrl(),
            'manageUrl' => $demo->manageUrl($this->enquiry),
            'trialUrl' => url('/start'),
            'owner' => $this->enquiry->demoOwner?->name,
        ]);
    }

    private function label(): string
    {
        return $this->enquiry->demo_at ? app(DemoSlots::class)->label(CarbonImmutable::parse($this->enquiry->demo_at)) : '';
    }
}
