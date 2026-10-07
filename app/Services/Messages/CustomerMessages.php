<?php

namespace App\Services\Messages;

use App\Actions\Customers\RecordTimelineEvent;
use App\Enums\CustomerStatus;
use App\Enums\TimelineEventType;
use App\Models\Appointment;
use App\Models\BusinessProfile;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Notifications\CustomerEmail;
use App\Services\Billing\FeatureAccess;
use App\Support\Phone;
use Illuminate\Support\Facades\Notification;

/**
 * Emails to a business's own customers about their appointments (spec §26–27): confirmations,
 * reminders, changes and cancellations, in the business's words and timezone, from the business's
 * name with replies going to the business. Each one is noted on the customer's timeline.
 */
class CustomerMessages
{
    public function __construct(private readonly RecordTimelineEvent $timeline) {}

    /** @return array{subject: string, body: string, is_active: bool, lead_hours: int|null, custom: bool} */
    public function template(Organization $organization, string $key): array
    {
        $default = config("customer_messages.templates.$key") ?? throw new \InvalidArgumentException("Unknown message [$key].");
        $saved = MessageTemplate::query()->forOrganization($organization)->where('key', $key)->first();

        return [
            'subject' => $saved->subject ?? $default['subject'],
            'body' => $saved->body ?? $default['body'],
            'is_active' => $saved->is_active ?? true,
            'lead_hours' => $saved->lead_hours ?? ($default['lead_hours'] ?? null),
            'custom' => $saved !== null,
        ];
    }

    /** @return array<string, string> placeholder => value for one appointment */
    public function values(Appointment $appointment): array
    {
        $organization = $appointment->organization;
        $timezone = $appointment->timezone ?: $organization->timezoneOrDefault();
        $starts = $appointment->starts_at->setTimezone($timezone);
        $profile = BusinessProfile::query()->forOrganization($organization)->first();

        return [
            'first_name' => $appointment->customer?->first_name ?: 'there',
            'business' => $profile?->display_name ?: $organization->name,
            'service' => $appointment->service?->name ?: $appointment->title,
            'date' => $starts->format('l, F j'),
            'time' => $starts->format('g:i A'),
            'address' => (string) $appointment->address,
            'phone' => $profile?->phone ? (Phone::display(Phone::normalize($profile->phone)) ?? $profile->phone) : '',
        ];
    }

    /** @param  array<string, string>  $values */
    public function render(string $text, array $values): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $hadPlaceholder = preg_match('/\{(\w+)\}/', $line) === 1;
            $filled = preg_replace_callback('/\{(\w+)\}/', fn (array $m) => $values[$m[1]] ?? $m[0], $line);
            // A line that was only a label and an empty value ("Address: ") is dropped.
            if ($hadPlaceholder && trim((string) preg_replace('/^[^:]{1,20}:\s*/', '', (string) $filled)) === '') {
                continue;
            }
            $lines[] = $filled;
        }

        return trim(implode("\n", $lines));
    }

    /** Can this appointment's customer get email at all? */
    public function reachable(Appointment $appointment): bool
    {
        $customer = $appointment->customer;

        return $customer !== null
            && filter_var($customer->email, FILTER_VALIDATE_EMAIL) !== false
            && ! in_array($customer->status, [CustomerStatus::Archived], true);
    }

    /** Send one of the templates about an appointment. True when an email went out. */
    public function send(Appointment $appointment, string $key): bool
    {
        $appointment->loadMissing(['organization', 'customer', 'service']);
        if (! app(FeatureAccess::class)->allows($appointment->organization, 'customer_emails')) {
            return false;   // not in the business's plan (D46)
        }
        $template = $this->template($appointment->organization, $key);
        if (! $template['is_active'] || ! $this->reachable($appointment)) {
            return false;
        }

        $values = $this->values($appointment);
        $subject = $this->render($template['subject'], $values);
        $body = $this->render($template['body'], $values);
        $replyTo = BusinessProfile::query()->forOrganization($appointment->organization)->value('email') ?: $appointment->organization->owner?->email;

        Notification::route('mail', $appointment->customer->email)
            ->notify(new CustomerEmail($subject, $body, $values['business'], $replyTo));

        $this->timeline->handle($appointment->customer, TimelineEventType::EmailSent, 'Email sent: '.config("customer_messages.templates.$key.label"),
            $subject, $appointment, ['template' => $key, 'to' => $appointment->customer->email]);

        return true;
    }
}
