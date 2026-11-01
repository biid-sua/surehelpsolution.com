<?php

namespace App\Services\Leads;

use App\Exceptions\TelephonyUnavailable;
use App\Jobs\RespondToLead;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\Lead;
use App\Models\LeadSettings;
use App\Models\Organization;
use App\Notifications\CustomerEmail;
use App\Services\Billing\FeatureAccess;
use App\Services\Business\BusinessHours;
use App\Services\Telephony\TelephonyProvider;
use App\Services\Telephony\Texting;
use App\Support\DemoGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * The instant reply to a new lead (D109), as the business set it up: a text and an email in their
 * name within seconds, and a call that rings the owner and connects them to the lead.
 *
 * Texts follow the texting rules (D61): never to a number that texted STOP, always with "Reply STOP
 * to opt out", and not at night: one that arrives between 9 PM and 8 AM (the business's time) waits
 * for the morning. The email is a direct reply to their request, as appointment emails are.
 */
class LeadResponder
{
    public const QUIET_FROM = 21;

    public const QUIET_UNTIL = 8;

    public function __construct(
        private readonly Texting $texting,
        private readonly TelephonyProvider $phone,
        private readonly BusinessHours $hours,
    ) {}

    /** @param  bool  $morning  the run held back for the night: send now */
    public function respond(Lead $lead, bool $morning = false): void
    {
        $organization = Organization::query()->find($lead->organization_id);
        if (! $organization || DemoGuard::isDemo($organization->id)) {
            return;   // demo businesses never text, email or call anyone (D94)
        }
        $settings = LeadSettings::for($organization);
        if (! $settings->is_enabled || ! app(FeatureAccess::class)->allows($organization, 'lead_response')) {
            return;
        }
        $business = BusinessProfile::query()->forOrganization($organization)->value('display_name') ?: $organization->name;
        $notes = [];

        if ($settings->text_lead && $lead->phone && ! $lead->texted_at) {
            $local = CarbonImmutable::now($organization->timezoneOrDefault());
            if (! $morning && ($local->hour >= self::QUIET_FROM || $local->hour < self::QUIET_UNTIL)) {
                $at = ($local->hour >= self::QUIET_FROM ? $local->addDay() : $local)->setTime(self::QUIET_UNTIL, 0);
                RespondToLead::dispatch($lead->id, morning: true)->delay($at);
                $notes[] = 'the text waits until '.$at->format('g A');
            } else {
                $text = $settings->textFor($lead->firstName(), $business);
                if (! preg_match('/\bSTOP\b/i', $text)) {
                    $text .= ' Reply STOP to opt out.';
                }
                $message = $this->texting->sendAutomatic($organization, $lead->phone, $text, 'lead_reply');
                if ($message?->status === 'sent') {
                    $lead->forceFill(['texted_at' => now()])->save();
                } else {
                    $notes[] = $message ? 'the text failed' : 'no text (texting is off or they opted out)';
                }
            }
        }

        if ($settings->email_lead && $lead->email && ! $lead->emailed_at) {
            $replyTo = BusinessProfile::query()->forOrganization($organization)->value('email');
            Notification::route('mail', $lead->email)->notify(new CustomerEmail(
                'Thanks for contacting '.$business, $settings->emailFor($lead->firstName(), $business), $business,
                filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : null, organizationId: $organization->id,
            ));
            $lead->forceFill(['emailed_at' => now()])->save();
        }

        if ($settings->connect_call && $lead->phone && $lead->call_status === null) {
            $notes[] = $this->ringOwner($organization, $lead, $settings);
        }

        $first = collect([$lead->texted_at, $lead->emailed_at])->filter()->min();
        $lead->forceFill([
            'first_response_at' => $lead->first_response_at ?? $first,
            'note' => collect($notes)->filter()->implode('; ') ?: null,
        ])->save();
    }

    /** Rings the owner's phone from the business's line; pressing 1 connects them to the lead. */
    private function ringOwner(Organization $organization, Lead $lead, LeadSettings $settings): ?string
    {
        $line = $this->texting->lineFor($organization);
        if (! $line?->transfer_number) {
            return 'no call (no owner number on the phone line)';
        }
        $openHours = BusinessHour::query()->forOrganization($organization)->exists();
        if ($settings->call_in_hours_only && $openHours && ! $this->hours->isOpenAt($organization, CarbonImmutable::now($organization->timezoneOrDefault()))) {
            return 'no call (outside your hours)';
        }

        try {
            $sid = $this->phone->placeCall($line->number, $line->transfer_number, route('webhooks.voice.lead-whisper', $lead), route('webhooks.voice.lead-status', $lead));
        } catch (TelephonyUnavailable $e) {
            $lead->forceFill(['call_status' => 'failed'])->save();

            return 'the call failed: '.$e->getMessage();
        }
        $lead->forceFill(['call_status' => 'calling_owner', 'call_sid' => $sid])->save();

        return null;
    }
}
