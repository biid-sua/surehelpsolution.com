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
use App\Services\Appointments\CustomerSelfService;
use App\Services\Billing\FeatureAccess;
use App\Services\Telephony\Texting;
use App\Support\Phone;
use Illuminate\Support\Facades\Notification;

/**
 * Emails to a business's own customers about their appointments (spec §26–27): confirmations,
 * reminders, changes and cancellations, in the business's words and timezone, from the business's
 * name with replies going to the business. Each one is noted on the customer's timeline.
 */
class CustomerMessages
{
    /** Emails that carry the customer's change-or-cancel link (CAL-09). */
    public const WITH_SELF_SERVICE = ['appointment_confirmed', 'appointment_reminder', 'appointment_changed'];

    public function __construct(
        private readonly RecordTimelineEvent $timeline,
        private readonly CustomerSelfService $selfService,
    ) {}

    /** @return array{subject: string, body: string, sms: string, is_active: bool, lead_hours: int|null, custom: bool} */
    public function template(Organization $organization, string $key): array
    {
        $default = config("customer_messages.templates.$key") ?? throw new \InvalidArgumentException("Unknown message [$key].");
        $saved = MessageTemplate::query()->forOrganization($organization)->where('key', $key)->first();

        return [
            'subject' => $saved->subject ?? $default['subject'],
            'body' => $saved->body ?? $default['body'],
            'sms' => $saved?->sms_body ?: (string) ($default['sms'] ?? ''),
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

    /**
     * Send one of the templates about an appointment: by email, and by text to customers who agreed
     * to texts (D61). True when either went out.
     */
    public function send(Appointment $appointment, string $key): bool
    {
        $appointment->loadMissing(['organization', 'customer', 'service']);
        if (! app(FeatureAccess::class)->allows($appointment->organization, 'customer_emails')) {
            return false;   // not in the business's plan (D46)
        }
        $template = $this->template($appointment->organization, $key);
        if (! $template['is_active']) {
            return false;
        }
        $values = $this->values($appointment);

        $emailed = $this->email($appointment, $key, $template, $values);
        $texted = $this->text($appointment, $key, $template['sms'], $values);

        return $emailed || $texted;
    }

    /**
     * @param  array{subject: string, body: string}  $template
     * @param  array<string, string>  $values
     */
    private function email(Appointment $appointment, string $key, array $template, array $values): bool
    {
        if (! $this->reachable($appointment)) {
            return false;
        }
        $subject = $this->render($template['subject'], $values);
        $body = $this->render($template['body'], $values);
        $replyTo = BusinessProfile::query()->forOrganization($appointment->organization)->value('email') ?: $appointment->organization->owner?->email;

        Notification::route('mail', $appointment->customer->email)
            ->notify(new CustomerEmail($subject, $body, $values['business'], $replyTo,
                in_array($key, self::WITH_SELF_SERVICE, true) ? $this->selfService->link($appointment) : null));

        $this->timeline->handle($appointment->customer, TimelineEventType::EmailSent, 'Email sent: '.config("customer_messages.templates.$key.label"),
            $subject, $appointment, ['template' => $key, 'to' => $appointment->customer->email]);

        return true;
    }

    /**
     * The short text version, from the business's SureHelp number, only to customers who agreed to
     * texts and never to numbers that texted STOP. Replies land in the Inbox.
     *
     * @param  array<string, string>  $values
     */
    private function text(Appointment $appointment, string $key, string $text, array $values): bool
    {
        $customer = $appointment->customer;
        if ($text === '' || ! $customer || ! $customer->sms_consent || ! $customer->phone_e164 || $customer->status === CustomerStatus::Archived) {
            return false;
        }
        if (! $values['phone']) {
            $text = str_replace(' or call {phone}', '', $text);
        }
        $message = app(Texting::class)->sendAutomatic($appointment->organization, $customer->phone_e164, $this->render($text, $values), $key);
        if (! $message || $message->status !== 'sent') {
            return false;
        }
        $this->timeline->handle($customer, TimelineEventType::SmsSent, 'Text sent: '.config("customer_messages.templates.$key.label"),
            $message->body, $appointment, ['template' => $key, 'to' => $customer->phone_e164]);

        return true;
    }
}
