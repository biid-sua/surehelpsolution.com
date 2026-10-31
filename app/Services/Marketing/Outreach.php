<?php

namespace App\Services\Marketing;

use App\Actions\Customers\RecordTimelineEvent;
use App\Enums\AppointmentStatus;
use App\Enums\CustomerStatus;
use App\Enums\TimelineEventType;
use App\Models\Appointment;
use App\Models\BusinessProfile;
use App\Models\BusinessService;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ServiceReminder;
use App\Notifications\CustomerEmail;
use App\Services\Business\Branding;
use App\Services\Telephony\Texting;
use App\Support\DemoGuard;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Service reminders and win-back campaigns (D116): messages to past customers that bring them back.
 *
 * These are marketing, so the rules are stricter than for appointment messages:
 * - texts only to customers who agreed to texts (sms_consent), never to a number that texted STOP;
 * - emails always carry an unsubscribe link, and anyone who unsubscribed gets neither;
 * - nothing goes out between 8 PM and 9 AM in the business's time zone;
 * - archived customers and demo businesses get nothing.
 */
class Outreach
{
    public const QUIET_FROM = 20;

    public const QUIET_UNTIL = 9;

    /** How long after a message a booking counts as brought back by it. */
    public const RESULT_DAYS = 30;

    public function __construct(
        private readonly Texting $texting,
        private readonly Branding $branding,
        private readonly RecordTimelineEvent $timeline,
    ) {}

    public function quiet(Organization $organization): bool
    {
        $hour = CarbonImmutable::now($organization->timezoneOrDefault())->hour;

        return $hour >= self::QUIET_FROM || $hour < self::QUIET_UNTIL;
    }

    /** When sending may start again: 9 AM local, today or tomorrow. */
    public function nextWindow(Organization $organization): CarbonImmutable
    {
        $local = CarbonImmutable::now($organization->timezoneOrDefault());
        $start = $local->setTime(self::QUIET_UNTIL, 5);

        return $local->hour >= self::QUIET_FROM ? $start->addDay() : $start;
    }

    /** @return list<string> the ways this customer may get marketing ("sms", "email") */
    public function reachableBy(Customer $customer, Organization $organization): array
    {
        if ($customer->marketing_opt_out_at || in_array($customer->status, [CustomerStatus::Archived], true)) {
            return [];
        }

        return array_values(array_filter([
            $customer->sms_consent && $customer->phone_e164 && $this->texting->enabled() && $this->texting->lineFor($organization)
                && ! $this->texting->optedOut($organization->id, $customer->phone_e164) ? 'sms' : null,
            filter_var($customer->email, FILTER_VALIDATE_EMAIL) !== false ? 'email' : null,
        ]));
    }

    /**
     * Customers a campaign would go to.
     *
     * @param  array{inactive_months?: int|null, tag_id?: int|null, service_id?: int|null}  $audience
     * @return Builder<Customer>
     */
    public function audience(Organization $organization, array $audience): Builder
    {
        $query = Customer::query()->forOrganization($organization)->whereNull('marketing_opt_out_at')
            ->where('status', '!=', CustomerStatus::Archived->value);
        $months = (int) ($audience['inactive_months'] ?? 0);
        if ($months > 0) {
            // Had work done before, and nothing booked since the cut-off.
            $cutoff = now()->subMonths($months);
            $query->whereHas('appointments', fn ($q) => $q->where('status', AppointmentStatus::Completed->value))
                ->whereDoesntHave('appointments', fn ($q) => $q->where('starts_at', '>=', $cutoff)->where('status', '!=', AppointmentStatus::Cancelled->value));
        }
        if (! empty($audience['tag_id'])) {
            $query->whereHas('tags', fn ($q) => $q->whereKey((int) $audience['tag_id']));
        }
        if (! empty($audience['service_id'])) {
            $query->whereHas('appointments', fn ($q) => $q->where('service_id', (int) $audience['service_id'])->where('status', AppointmentStatus::Completed->value));
        }

        return $query;
    }

    /**
     * One message to one customer. Returns how it went out (maybe nowhere).
     *
     * @param  list<string>  $channels
     * @return list<string>
     */
    public function deliver(Organization $organization, Customer $customer, array $channels, ?string $text, ?string $subject, ?string $body, string $reason, array $extra = []): array
    {
        if (DemoGuard::isDemo($organization->id)) {
            return [];
        }
        $values = $this->values($organization, $customer, $extra);
        $allowed = $this->reachableBy($customer, $organization);
        $sent = [];
        if (in_array('sms', $channels, true) && in_array('sms', $allowed, true) && filled($text)) {
            $message = $this->render((string) $text, $values);
            if (! preg_match('/\bSTOP\b/i', $message)) {
                $message .= ' Reply STOP to opt out.';
            }
            $result = $this->texting->sendAutomatic($organization, (string) $customer->phone_e164, $message, $reason);
            if ($result && $result->status !== 'failed') {
                $sent[] = 'sms';
            }
        }
        if (in_array('email', $channels, true) && in_array('email', $allowed, true) && filled($subject) && filled($body)) {
            $unsubscribe = URL::signedRoute('marketing.unsubscribe', ['customer' => $customer->ulid]);
            $replyTo = BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->value('email');
            Notification::route('mail', $customer->email)->notify(new CustomerEmail(
                $this->render((string) $subject, $values),
                $this->render((string) $body, $values)."\n\nDon't want these emails? Unsubscribe: ".$unsubscribe,
                $values['{business}'], $replyTo ?: null, organizationId: $organization->id,
            ));
            $sent[] = 'email';
        }
        if ($sent !== []) {
            $this->timeline->handle($customer, $sent === ['email'] ? TimelineEventType::EmailSent : TimelineEventType::SmsSent,
                $reason === 'service_reminder' ? 'Service reminder sent' : 'Campaign sent', $extra['label'] ?? null);
        }

        return $sent;
    }

    /**
     * Reminders due today: completed jobs whose service has a reminder, the set number of months
     * ago (a week's grace, so a missed day still goes out), not reminded yet, and not booked again since.
     *
     * @return int reminders sent
     */
    public function sendReminders(Organization $organization): int
    {
        if (DemoGuard::isDemo($organization->id) || $this->quiet($organization)) {
            return 0;
        }
        $sent = 0;
        $services = BusinessService::query()->forOrganization($organization)->whereNotNull('reminder_months')->where('reminder_months', '>', 0)->get();
        foreach ($services as $service) {
            $due = now()->subMonths((int) $service->reminder_months);
            $jobs = Appointment::query()->forOrganization($organization)->where('service_id', $service->id)->where('status', AppointmentStatus::Completed->value)
                ->whereBetween('starts_at', [$due->copy()->subDays(7), $due])
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('service_reminders')->whereColumn('service_reminders.appointment_id', 'appointments.id'))
                ->with('customer')->limit(500)->get();
            foreach ($jobs as $job) {
                $customer = $job->customer;
                $booked = $customer && Appointment::query()->forOrganization($organization)->where('customer_id', $customer->id)
                    ->where('starts_at', '>', $job->starts_at)->where('status', '!=', AppointmentStatus::Cancelled->value)->exists();
                if (! $customer || $booked) {
                    ServiceReminder::create(['organization_id' => $organization->id, 'appointment_id' => $job->id, 'customer_id' => $job->customer_id,
                        'service_id' => $service->id, 'channels' => [], 'sent_at' => now()]);   // nothing to send; never look again

                    continue;
                }
                $text = $service->reminder_text ?: 'Hi {first_name}, it\'s been a while since your {service} with {business}. Time to book the next one? Reply or call {phone}.';
                $channels = $this->deliver($organization, $customer, ['sms', 'email'], $text, 'Time for your next {service}', $text, 'service_reminder', ['{service}' => $service->name, 'label' => $service->name]);
                ServiceReminder::create(['organization_id' => $organization->id, 'appointment_id' => $job->id, 'customer_id' => $customer->id,
                    'service_id' => $service->id, 'channels' => $channels, 'sent_at' => now()]);
                $sent += $channels !== [] ? 1 : 0;
            }
        }

        return $sent;
    }

    /**
     * Sends a campaign's next batch, if it's a good hour. Returns false when the rest must wait.
     */
    public function sendBatch(Campaign $campaign, int $size = 200): bool
    {
        $organization = Organization::query()->findOrFail($campaign->organization_id);
        if ($this->quiet($organization)) {
            return false;
        }
        $pending = CampaignRecipient::query()->where('campaign_id', $campaign->id)->whereNull('sent_at')->with('customer')->limit($size)->get();
        foreach ($pending as $recipient) {
            $channels = $recipient->customer
                ? $this->deliver($organization, $recipient->customer, $campaign->channels, $campaign->text_body, $campaign->email_subject, $campaign->email_body, 'campaign', ['label' => $campaign->name])
                : [];
            $recipient->forceFill(['channels' => $channels, 'sent_at' => now()])->save();
            if ($channels !== []) {
                $campaign->increment('sent_count');
            }
        }
        if (! CampaignRecipient::query()->where('campaign_id', $campaign->id)->whereNull('sent_at')->exists()) {
            $campaign->forceFill(['status' => Campaign::SENT, 'sent_at' => $campaign->sent_at ?? now()])->save();
        }

        return true;
    }

    /** Recipients who booked within RESULT_DAYS of the campaign. */
    public function bookedAfter(Campaign $campaign): int
    {
        if (! $campaign->sent_at) {
            return 0;
        }

        return Appointment::query()->forOrganization($campaign->organization_id)
            ->whereIn('customer_id', CampaignRecipient::query()->where('campaign_id', $campaign->id)->whereNotNull('sent_at')->where('channels', '!=', '[]')->select('customer_id'))
            ->where('created_at', '>', $campaign->created_at)->where('created_at', '<=', $campaign->sent_at->copy()->addDays(self::RESULT_DAYS))
            ->where('status', '!=', AppointmentStatus::Cancelled->value)->distinct()->count('customer_id');
    }

    /** @param  array<string, string>  $values */
    public function render(string $text, array $values): string
    {
        return trim(strtr($text, $values));
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function values(Organization $organization, Customer $customer, array $extra): array
    {
        $profile = BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->first();

        return [
            '{first_name}' => $customer->first_name ?: 'there',
            '{business}' => $this->branding->for($organization)['name'],
            '{phone}' => Phone::display($profile?->phone) ?? (string) $profile?->phone,
            '{service}' => $extra['{service}'] ?? 'service',
        ];
    }
}
