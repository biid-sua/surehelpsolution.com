<?php

namespace App\Services\Sales;

use App\Exceptions\TelephonyUnavailable;
use App\Mail\SalesDemoBookedMail;
use App\Mail\SalesDemoFollowUpMail;
use App\Models\ContactSubmission;
use App\Models\PhoneNumber;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Telephony\TelephonyProvider;
use App\Support\Audit\Audit;
use App\Support\DemoGuard;
use App\Support\NotificationChannels;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Demo calls with the SureHelp team after they're booked (D96): the private link to move or cancel,
 * reminders, who runs each demo, what happened and what came of it, and the follow-up emails.
 * Statuses: booked → attended / no_show / cancelled (docs/state-machines.md).
 */
class DemoFollowUp
{
    public const STATUSES = ['booked' => 'Booked', 'attended' => 'Attended', 'no_show' => 'No-show', 'cancelled' => 'Cancelled'];

    public const OUTCOMES = ['trial' => 'Started a trial', 'customer' => 'Became a customer', 'not_interested' => 'Not interested'];

    public function __construct(private DemoSlots $slots, private Audit $audit, private TelephonyProvider $telephony) {}

    public function meetingUrl(): ?string
    {
        $url = trim((string) config('sales.demos.meeting_url'));

        return $url !== '' ? $url : null;
    }

    /** "Microsoft Teams" when the link is a Teams meeting; the generic location otherwise. */
    public function where(): string
    {
        $url = $this->meetingUrl();
        if (! $url) {
            return (string) config('sales.demos.location');
        }

        return (str_contains($url, 'teams.microsoft.com') || str_contains($url, 'teams.live.com') ? 'Microsoft Teams' : 'Video call').': '.$url;
    }

    /** The visitor's private page to see, move or cancel their demo. */
    public function manageUrl(ContactSubmission $enquiry): string
    {
        if (! $enquiry->demo_token) {
            $enquiry->forceFill(['demo_token' => Str::random(40)])->save();
        }

        return route('demo.show', $enquiry->demo_token);
    }

    /** A demo was just booked (in the chat, or moved on the visitor's page): invitation, fresh reminders. */
    public function booked(ContactSubmission $enquiry, bool $moved = false): void
    {
        $this->manageUrl($enquiry);
        $enquiry->forceFill(['demo_booked_at' => now(), 'demo_reminded_day_at' => null, 'demo_reminded_hour_at' => null])->save();
        Mail::to($enquiry->email)->queue(new SalesDemoBookedMail($enquiry->fresh(), $moved ? 'moved' : 'booked'));
    }

    /** The visitor picks a new time. False when it was just taken. */
    public function reschedule(ContactSubmission $enquiry, CarbonImmutable $at): bool
    {
        $before = $enquiry->demo_at?->toIso8601String();
        if (! $this->slots->book($enquiry, $at)) {
            return false;
        }
        $this->booked($enquiry, moved: $before !== null);
        $this->audit->record('enquiry.demo_moved', $enquiry, ['demo_at' => $before], ['demo_at' => $at->toIso8601String()], actor: null, label: $enquiry->name);
        $this->alertTeam($enquiry, 'Demo moved to '.$this->slots->label($at).': '.$enquiry->name);

        return true;
    }

    public function cancel(ContactSubmission $enquiry, ?User $by = null): void
    {
        $at = $enquiry->demo_at;
        $enquiry->forceFill(['demo_status' => 'cancelled', 'demo_booked_at' => now()])->save();
        Mail::to($enquiry->email)->queue(new SalesDemoBookedMail($enquiry->fresh(), 'cancelled'));
        $this->audit->record('enquiry.demo_cancelled', $enquiry, ['demo_at' => $at?->toIso8601String()], actor: $by, label: $enquiry->name);
        if (! $by) {
            $this->alertTeam($enquiry, 'Demo cancelled by the visitor: '.$enquiry->name);
        }
    }

    /** After the call: attended or no-show. The matching follow-up email goes once. */
    public function mark(ContactSubmission $enquiry, string $status, User $by): void
    {
        abort_unless(in_array($status, ['attended', 'no_show'], true) && $enquiry->demo_at !== null, 422);
        $old = $enquiry->demo_status;
        $enquiry->forceFill(['demo_status' => $status])->save();
        $this->audit->record('enquiry.demo_marked', $enquiry, ['demo_status' => $old], ['demo_status' => $status], actor: $by, label: $enquiry->name);
        if ($enquiry->demo_followed_up_at === null && $old !== $status) {
            $this->manageUrl($enquiry);
            Mail::to($enquiry->email)->queue(new SalesDemoFollowUpMail($enquiry->fresh(), $status === 'attended' ? 'recap' : 'missed'));
            $enquiry->forceFill(['demo_followed_up_at' => now()])->save();
        }
    }

    public function outcome(ContactSubmission $enquiry, ?string $outcome, User $by): void
    {
        abort_unless($outcome === null || array_key_exists($outcome, self::OUTCOMES), 422);
        $old = $enquiry->demo_outcome;
        $enquiry->forceFill(['demo_outcome' => $outcome])->save();
        $this->audit->record('enquiry.demo_outcome', $enquiry, ['outcome' => $old], ['outcome' => $outcome], actor: $by, label: $enquiry->name);
    }

    public function assign(ContactSubmission $enquiry, ?User $owner, User $by): void
    {
        $enquiry->forceFill(['demo_owner_user_id' => $owner?->id])->save();
        $this->audit->record('enquiry.demo_assigned', $enquiry, new: ['owner' => $owner?->name], actor: $by, label: $enquiry->name);
    }

    /**
     * The scheduled pass (every 5 minutes): reminders before the call, spotting demos that turned into a
     * sign-up or a paying customer, and the nudge a few days after a demo with no trial.
     *
     * @return array{reminders: int, texts: int, signed_up: int, nudges: int}
     */
    public function run(): array
    {
        $done = ['reminders' => 0, 'texts' => 0, 'signed_up' => 0, 'nudges' => 0];
        $now = now();

        $upcoming = ContactSubmission::query()->where('demo_status', 'booked')->whereBetween('demo_at', [$now, $now->copy()->addHours(25)])->get();
        foreach ($upcoming as $enquiry) {
            $minutes = $now->diffInMinutes($enquiry->demo_at);
            $recent = $enquiry->demo_booked_at && $enquiry->demo_booked_at->gt($now->copy()->subHours(12));
            if ($enquiry->demo_reminded_day_at === null && $minutes <= 24 * 60 && $minutes > 120 && ! $recent) {
                $this->remind($enquiry, 'day');
                $done['reminders']++;
            }
            if ($enquiry->demo_reminded_hour_at === null && $minutes <= 65 && $minutes > 5) {
                $done['texts'] += $this->remind($enquiry, 'hour') ? 1 : 0;
                $done['reminders']++;
            }
        }

        // A demo that led to a sign-up: the owner's email matches. Paid and no longer trialling: a customer.
        ContactSubmission::query()->whereNotNull('demo_status')->where('created_at', '>=', $now->copy()->subDays(120))
            ->where(fn ($q) => $q->whereNull('demo_outcome')->orWhere('demo_outcome', 'trial'))
            ->each(function (ContactSubmission $enquiry) use (&$done) {
                $owner = User::query()->where('email', mb_strtolower(trim($enquiry->email)))->where('role', 'client')->first();
                $organization = $owner?->organizations()->orderBy('organizations.id')->first();
                if (! $organization) {
                    return;
                }
                $paying = Subscription::withoutGlobalScopes()->where('organization_id', $organization->id)->where('status', 'active')->exists();
                $outcome = $paying ? 'customer' : 'trial';
                if ($enquiry->demo_outcome !== $outcome || $enquiry->signed_up_organization_id !== $organization->id) {
                    $enquiry->forceFill(['demo_outcome' => $outcome, 'signed_up_organization_id' => $organization->id])->save();
                    $this->audit->record('enquiry.demo_outcome', $enquiry, new: ['outcome' => $outcome, 'automatic' => true], actor: null, label: $enquiry->name);
                    $done['signed_up']++;
                }
            });

        ContactSubmission::query()->where('demo_status', 'attended')->whereNull('demo_outcome')->whereNull('demo_nudged_at')
            ->whereNotNull('demo_followed_up_at')->where('demo_followed_up_at', '<=', $now->copy()->subDays((int) config('sales.demos.nudge_after_days', 3)))
            ->each(function (ContactSubmission $enquiry) use (&$done) {
                Mail::to($enquiry->email)->queue(new SalesDemoFollowUpMail($enquiry, 'nudge'));
                $enquiry->forceFill(['demo_nudged_at' => now()])->save();
                $done['nudges']++;
            });

        return $done;
    }

    /** One reminder by email; the hour-before one also by text when they agreed to texts. True when a text went. */
    private function remind(ContactSubmission $enquiry, string $which): bool
    {
        $this->manageUrl($enquiry);
        Mail::to($enquiry->email)->queue(new SalesDemoFollowUpMail($enquiry, $which === 'day' ? 'reminder_day' : 'reminder_hour'));
        $enquiry->forceFill([$which === 'day' ? 'demo_reminded_day_at' : 'demo_reminded_hour_at' => now()])->save();

        $to = $enquiry->phone ? PhoneNumber::normalize($enquiry->phone) : null;
        if ($which !== 'hour' || ! $enquiry->sms_consent || ! $to || ! NotificationChannels::enabled('sms') || DemoGuard::reservedEmail($enquiry->email)) {
            return false;
        }
        $local = $enquiry->demo_at->setTimezone($this->slots->timezone());
        $join = $this->meetingUrl() ? ' Join: '.$this->meetingUrl() : '';
        $body = 'SureHelp: your demo starts at '.$local->format('g:i A T').'.'.$join.' Need another time? '.$this->manageUrl($enquiry);
        try {
            $this->telephony->sendSms((string) config('telephony.notify_from'), $to, $body, route('webhooks.sms.status'));

            return true;
        } catch (TelephonyUnavailable $e) {
            report($e);

            return false;
        }
    }

    private function alertTeam(ContactSubmission $enquiry, string $subject): void
    {
        $to = config('sales.notify') ?: config('mail.contact_to', config('company.email'));
        if ($to) {
            Mail::to($to)->queue(new SalesDemoFollowUpMail($enquiry, 'team', $subject));
        }
    }
}
