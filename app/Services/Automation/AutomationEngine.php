<?php

namespace App\Services\Automation;

use App\Actions\Customers\RecordTimelineEvent;
use App\Actions\Notifications\NotifyOrganization;
use App\Actions\Tasks\CreateTask;
use App\Enums\AppointmentStatus;
use App\Enums\CustomerStatus;
use App\Enums\EscalationStatus;
use App\Enums\InboxChannel;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\TimelineEventType;
use App\Models\Appointment;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\BusinessProfile;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Escalation;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\Task;
use App\Models\VoiceCall;
use App\Notifications\AutomationNotice;
use App\Notifications\CustomerEmail;
use App\Services\Billing\FeatureAccess;
use App\Services\Business\BusinessHours;
use App\Services\Growth\LinkPage;
use App\Services\Messages\CustomerMessages;
use App\Services\Telephony\Texting;
use App\Support\Phone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Runs businesses' automations (spec §42–43, D50; Automations 2.0, D80).
 *
 * fire(): a trigger happened, so a run is scheduled for each matching active automation (one per automation
 * and subject, so a repeated trigger never doubles). runDue(): every minute, due runs execute, after
 * re-checking that they still apply (the appointment is still completed, the customer still reachable,
 * the automation still on). Failures retry up to three times, then stay "failed" in the history.
 */
class AutomationEngine
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly CustomerMessages $messages,
        private readonly RecordTimelineEvent $timeline,
        private readonly CreateTask $createTask,
        private readonly NotifyOrganization $notify,
        private readonly FeatureAccess $features,
        private readonly Texting $texting,
        private readonly BusinessHours $hours,
    ) {}

    /** @return int runs scheduled */
    public function fire(string $trigger, Model $subject): int
    {
        $type = Automation::SUBJECT_FOR[$trigger] ?? null;
        if (! $type || ! $subject->getAttribute('organization_id')) {
            return 0;
        }
        $automations = Automation::withoutGlobalScopes()->where('organization_id', $subject->getAttribute('organization_id'))
            ->where('trigger', $trigger)->where('is_active', true)->get();
        $scheduled = 0;
        foreach ($automations as $automation) {
            $runAt = $this->runAt($automation, $subject);
            if ($runAt === null || $this->conditionsHold($automation, $subject) !== null) {
                continue;
            }
            // A booking can move again and again: each move may run the automation once more.
            if ($trigger === 'appointment_rescheduled') {
                AutomationRun::withoutGlobalScopes()->where('automation_id', $automation->id)->where('subject_type', $type)
                    ->where('subject_id', $subject->getKey())->whereNotIn('status', ['pending', 'running'])->delete();
            }
            $scheduled += AutomationRun::withoutGlobalScopes()->insertOrIgnore([
                'automation_id' => $automation->id,
                'organization_id' => $automation->organization_id,
                'subject_type' => $type,
                'subject_id' => $subject->getKey(),
                'status' => 'pending',
                'run_at' => $runAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $scheduled;
    }

    /** When it should run: after the wait, or for "coming up", that long before the start. Null: too late. */
    private function runAt(Automation $automation, Model $subject): ?CarbonInterface
    {
        if (! $automation->waitsBefore()) {
            return now()->addMinutes($automation->delay_minutes);
        }
        if (! $subject instanceof Appointment || $subject->starts_at->lte(now())) {
            return null;
        }
        $at = $subject->starts_at->copy()->subMinutes($automation->delay_minutes);

        return $at->lt(now()) ? now() : $at;
    }

    /** An appointment moved: "coming up" steps follow the new time (or are scheduled now if new). */
    public function rescheduled(Appointment $appointment): void
    {
        $pending = AutomationRun::withoutGlobalScopes()->with('automation')->where('subject_type', 'appointment')
            ->where('subject_id', $appointment->id)->where('status', 'pending')->get();
        foreach ($pending as $run) {
            if ($run->automation?->waitsBefore()) {
                $at = $this->runAt($run->automation, $appointment);
                $at ? $run->forceFill(['run_at' => $at])->save() : $run->forceFill(['status' => 'cancelled', 'result' => 'The appointment is no longer ahead.', 'ran_at' => now()])->save();
            }
        }
        $this->fire('appointment_upcoming', $appointment);
    }

    /**
     * Customers who haven't been back for an automation's number of days (once a day). Each customer is
     * reached once per automation.
     */
    public function scanInactive(): int
    {
        $fired = 0;
        foreach (Automation::withoutGlobalScopes()->where('trigger', 'customer_inactive')->where('is_active', true)->get() as $automation) {
            $days = max(7, (int) $automation->condition('inactive_days', 180));
            Customer::withoutGlobalScopes()->where('organization_id', $automation->organization_id)
                ->whereNot('status', CustomerStatus::Archived)
                ->whereHas('appointments', fn ($q) => $q->where('status', AppointmentStatus::Completed))
                ->whereDoesntHave('appointments', fn ($q) => $q->where(fn ($q) => $q->where('starts_at', '>', now()->subDays($days))->whereNot('status', AppointmentStatus::Cancelled)))
                ->whereNotExists(fn ($q) => $q->from('automation_runs')->whereColumn('automation_runs.subject_id', 'customers.id')
                    ->where('automation_runs.subject_type', 'customer')->where('automation_runs.automation_id', $automation->id))
                ->limit(500)->get()
                ->each(function (Customer $customer) use ($automation, &$fired) {
                    $fired += $this->fireOne($automation, $customer);
                });
        }

        return $fired;
    }

    private function fireOne(Automation $automation, Model $subject): int
    {
        if ($this->conditionsHold($automation, $subject) !== null) {
            return 0;
        }

        return AutomationRun::withoutGlobalScopes()->insertOrIgnore([
            'automation_id' => $automation->id, 'organization_id' => $automation->organization_id,
            'subject_type' => Automation::SUBJECT_FOR[$automation->trigger], 'subject_id' => $subject->getKey(),
            'status' => 'pending', 'run_at' => now()->addMinutes($automation->delay_minutes), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{done: int, skipped: int, failed: int} */
    public function runDue(int $limit = 200): array
    {
        $counts = ['done' => 0, 'skipped' => 0, 'failed' => 0];
        // "Hasn't been back for a while" is checked once a day.
        if (Cache::add('automations:inactive-scan:'.now()->toDateString(), true, now()->addDay())) {
            $this->scanInactive();
        }
        // A worker that died mid-run leaves "running" behind: give those another go.
        AutomationRun::withoutGlobalScopes()->where('status', 'running')->where('updated_at', '<', now()->subMinutes(15))
            ->update(['status' => 'pending', 'updated_at' => now()]);
        $due = AutomationRun::withoutGlobalScopes()->where('status', 'pending')->where('run_at', '<=', now())
            ->orderBy('run_at')->limit($limit)->pluck('id');

        foreach ($due as $id) {
            // Claim it, so overlapping workers never run the same one twice.
            if (! AutomationRun::withoutGlobalScopes()->whereKey($id)->where('status', 'pending')->update(['status' => 'running', 'updated_at' => now()])) {
                continue;
            }
            $status = $this->run(AutomationRun::withoutGlobalScopes()->with('automation')->findOrFail($id));
            $counts[$status === 'done' ? 'done' : ($status === 'failed' ? 'failed' : 'skipped')]++;
        }

        return $counts;
    }

    private function run(AutomationRun $run): string
    {
        $automation = $run->automation;
        $subject = $run->subject();

        $reason = match (true) {
            ! $automation || ! $automation->is_active => 'The automation was switched off.',
            ! $subject => 'What it was about no longer exists.',
            ! Organization::query()->find($run->organization_id)?->isServing() => 'The business\'s service is paused.',
            default => $this->conditionsHold($automation, $subject),
        };
        if ($reason !== null) {
            return $this->finish($run, 'cancelled', $reason);
        }

        try {
            $result = $this->perform($automation, $subject);
        } catch (SkipRun $e) {
            return $this->finish($run, 'skipped', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            $attempts = $run->attempts + 1;
            if ($attempts < self::MAX_ATTEMPTS) {
                $run->forceFill(['status' => 'pending', 'attempts' => $attempts, 'run_at' => now()->addMinutes(5 * $attempts), 'result' => 'Will retry: '.mb_substr($e->getMessage(), 0, 200)])->save();

                return 'retry';
            }

            return $this->finish($run, 'failed', mb_substr($e->getMessage(), 0, 400), $attempts);
        }

        return $this->finish($run, 'done', $result);
    }

    private function finish(AutomationRun $run, string $status, string $result, ?int $attempts = null): string
    {
        $run->forceFill(['status' => $status, 'result' => mb_substr($result, 0, 500), 'ran_at' => now(), 'attempts' => $attempts ?? $run->attempts + 1])->save();

        return $status;
    }

    /** Null when the automation applies to the subject now; otherwise why not. */
    public function conditionsHold(Automation $automation, Model $subject): ?string
    {
        if ($reason = $this->stillApplies($automation, $subject)) {
            return $reason;
        }
        if ($subject instanceof Appointment) {
            $services = array_map('intval', (array) $automation->condition('service_ids', []));
            if ($services !== [] && ! in_array((int) $subject->service_id, $services, true)) {
                return 'A different service.';
            }
        }
        $customer = $this->customerOf($subject);
        foreach ($automation->rules() as $rule) {
            if ($reason = $this->ruleFails($rule, $subject, $customer)) {
                return $reason;
            }
        }

        return null;
    }

    /** The thing that started it is still true (the job is still completed, the task still open…). */
    private function stillApplies(Automation $automation, Model $subject): ?string
    {
        $live = [AppointmentStatus::Pending, AppointmentStatus::Tentative, AppointmentStatus::Confirmed];

        return match (true) {
            $subject instanceof Appointment => match ($automation->trigger) {
                'appointment_completed' => $subject->status === AppointmentStatus::Completed ? null : 'The appointment is now '.mb_strtolower($subject->status->label()).'.',
                'appointment_cancelled' => $subject->status === AppointmentStatus::Cancelled ? null : 'The appointment is now '.mb_strtolower($subject->status->label()).'.',
                'appointment_no_show' => $subject->status === AppointmentStatus::NoShow ? null : 'The appointment is now '.mb_strtolower($subject->status->label()).'.',
                'appointment_upcoming' => ! in_array($subject->status, $live, true) ? 'The appointment is now '.mb_strtolower($subject->status->label()).'.'
                    : ($subject->starts_at->lte(now()) ? 'The appointment has already started.' : null),
                default => in_array($subject->status, $live, true) ? null : 'The appointment is now '.mb_strtolower($subject->status->label()).'.',
            },
            $subject instanceof Task => in_array($subject->status, [TaskStatus::Completed, TaskStatus::Cancelled], true) ? 'The task is done.' : null,
            $subject instanceof Escalation => $subject->status === EscalationStatus::Resolved ? 'The escalation is resolved.' : null,
            $subject instanceof Customer && $automation->trigger === 'customer_inactive' => $subject->appointments()
                ->where('starts_at', '>', now()->subDays(max(7, (int) $automation->condition('inactive_days', 180))))
                ->whereNot('status', AppointmentStatus::Cancelled)->exists() ? 'They\'ve booked again.' : null,
            default => null,
        };
    }

    /** @param array{field: string, op: string, value?: string} $rule */
    private function ruleFails(array $rule, Model $subject, ?Customer $customer): ?string
    {
        $op = $rule['op'];
        $value = (string) ($rule['value'] ?? '');

        switch ($rule['field']) {
            case 'customer_tag':
                $has = $customer && $customer->tags()->whereKey((int) $value)->exists();

                return ($op === 'has') === $has ? null : ($op === 'has' ? 'The customer doesn\'t have the tag.' : 'The customer has the tag.');
            case 'customer_status':
                $is = $customer?->status?->value === $value;

                return ($op === 'is') === $is ? null : 'The customer\'s status doesn\'t match.';
            case 'first_visit':
                $earlier = $customer ? $customer->appointments()->where('status', AppointmentStatus::Completed)
                    ->when($subject instanceof Appointment, fn ($q) => $q->whereKeyNot($subject->getKey()))->exists() : false;

                return ($op === 'yes') === ! $earlier ? null : ($op === 'yes' ? 'Not their first visit.' : 'Their first visit.');
            case 'has_email':
                $known = $customer && filter_var($customer->email, FILTER_VALIDATE_EMAIL) !== false;

                return ($op === 'yes') === $known ? null : ($op === 'yes' ? 'No email address.' : 'They have an email address.');
            case 'has_phone':
                $known = $customer?->phone_e164 || ($subject instanceof VoiceCall && $subject->from_number);

                return ($op === 'yes') === (bool) $known ? null : ($op === 'yes' ? 'No phone number.' : 'They have a phone number.');
            case 'call_outcome':
                $category = $subject instanceof CallLog ? $subject->outcomeCategory()?->value : null;

                return ($op === 'is') === ($category === $value) ? null : 'A different call outcome.';
            case 'business_hours':
                $organization = Organization::query()->find($subject->getAttribute('organization_id'));
                $at = $subject->getAttribute('started_at') ?? $subject->getAttribute('created_at') ?? now();
                $open = $organization && $this->hours->isOpenAt($organization, $at);

                return ($op === 'open') === $open ? null : ($op === 'open' ? 'It happened while you were closed.' : 'It happened while you were open.');
        }

        return null;
    }

    private function customerOf(Model $subject): ?Customer
    {
        return match (true) {
            $subject instanceof Customer => $subject,
            $subject instanceof Appointment, $subject instanceof CallLog, $subject instanceof VoiceCall,
            $subject instanceof Conversation, $subject instanceof Escalation, $subject instanceof Task => $subject->customer()->first(),
            default => null,
        };
    }

    /** @return string what happened, for the history */
    private function perform(Automation $automation, Model $subject): string
    {
        $organization = Organization::query()->findOrFail($automation->organization_id);
        $customer = $this->customerOf($subject);

        return match ($automation->action) {
            'send_email' => $this->sendEmail($automation, $organization, $subject, $customer),
            'create_task' => $this->createTask($automation, $organization, $subject, $customer),
            'notify_team' => $this->notifyTeam($automation, $organization, $subject, $customer),
            'send_text' => $this->sendText($automation, $organization, $subject, $customer),
            'email_address' => $this->emailAddress($automation, $organization, $subject, $customer),
            'add_tag', 'remove_tag' => $this->tag($automation, $organization, $customer),
            'set_status' => $this->setStatus($automation, $customer),
            default => throw new SkipRun('Unknown action.'),
        };
    }

    private function sendEmail(Automation $automation, Organization $organization, Model $subject, ?Customer $customer): string
    {
        if (! $this->features->allows($organization, 'customer_emails')) {
            throw new SkipRun('Customer emails aren\'t in the business\'s plan.');
        }
        if (! $customer || filter_var($customer->email, FILTER_VALIDATE_EMAIL) === false || $customer->status === CustomerStatus::Archived) {
            throw new SkipRun('The customer has no email address.');
        }
        if ($automation->config('consent_only', true) && ! $customer->email_consent) {
            throw new SkipRun('The customer hasn\'t agreed to emails.');
        }

        $values = $this->values($automation, $organization, $subject, $customer);
        $subjectLine = $this->messages->render((string) $automation->config('subject', ''), $values);
        $body = $this->messages->render((string) $automation->config('body', ''), $values);
        $replyTo = BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->value('email') ?: $organization->owner?->email;

        Notification::route('mail', $customer->email)->notify(new CustomerEmail($subjectLine, $body, $values['business'], $replyTo));
        $this->timeline->handle($customer, TimelineEventType::EmailSent, 'Email sent: '.$automation->name, $subjectLine, $subject instanceof Appointment ? $subject : null,
            ['automation' => $automation->ulid, 'to' => $customer->email]);

        return 'Emailed '.$customer->email.'.';
    }

    private function createTask(Automation $automation, Organization $organization, Model $subject, ?Customer $customer): string
    {
        $values = $this->values($automation, $organization, $subject, $customer);
        $task = $this->createTask->handle($organization, [
            'type' => TaskType::FollowUp,
            'title' => mb_substr($this->messages->render((string) $automation->config('title', 'Follow up with {first_name}'), $values), 0, 200),
            'description' => 'Created by the automation "'.$automation->name.'".',
            'customer_id' => $customer?->id,
            'due_at' => now()->addHours((int) $automation->config('due_hours', 24)),
        ], null, 'automation');

        return 'Created the task "'.$task->title.'".';
    }

    private function notifyTeam(Automation $automation, Organization $organization, Model $subject, ?Customer $customer): string
    {
        $values = $this->values($automation, $organization, $subject, $customer);
        $text = $this->messages->render((string) $automation->config('message', '{first_name}: {service}'), $values);
        $sent = $this->notify->handle($organization, new AutomationNotice($organization, $automation->name, $text, $customer?->ulid), 'customers.view');

        return 'Told '.$sent->count().' '.str('person')->plural($sent->count()).'.';
    }

    private function sendText(Automation $automation, Organization $organization, Model $subject, ?Customer $customer): string
    {
        $phone = $customer?->phone_e164 ?: ($subject instanceof VoiceCall ? $subject->from_number : null)
            ?: ($subject instanceof Conversation && $subject->channel === InboxChannel::Sms ? $subject->external_thread_id : null);
        if (! $phone) {
            throw new SkipRun('No mobile number for this customer.');
        }
        // They got in touch themselves (a call or a message), so a reply is expected; everything else needs consent.
        $theyReachedOut = in_array($automation->trigger, ['call_missed', 'call_logged', 'conversation_started'], true);
        if (($automation->config('consent_only', true) || ! $theyReachedOut) && ! $customer?->sms_consent) {
            throw new SkipRun('The customer hasn\'t agreed to texts.');
        }
        if (! $this->texting->enabled() || ! $this->texting->lineFor($organization)) {
            throw new SkipRun('Texting isn\'t switched on for this business yet.');
        }

        $body = $this->messages->render((string) $automation->config('text', ''), $this->values($automation, $organization, $subject, $customer));
        $message = $this->texting->sendAutomatic($organization, $phone, $body, 'automation');
        if ($message === null) {
            throw new SkipRun('This number can\'t be texted (not a US or Canadian mobile, or it texted STOP).');
        }
        if ($message->status === 'failed') {
            throw new SkipRun('The text didn\'t go out: '.$message->error);
        }

        return 'Texted '.Phone::display($phone).'.';
    }

    private function emailAddress(Automation $automation, Organization $organization, Model $subject, ?Customer $customer): string
    {
        $to = (string) $automation->config('to', '');
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new SkipRun('No valid address to email.');
        }
        $values = $this->values($automation, $organization, $subject, $customer)
            + ['customer' => $customer?->fullName() ?: 'A customer'];
        $subjectLine = $this->messages->render((string) $automation->config('subject', ''), $values);
        $body = $this->messages->render((string) $automation->config('body', ''), $values);
        Notification::route('mail', $to)->notify(new CustomerEmail($subjectLine, $body, $values['business'], null));

        return 'Emailed '.$to.'.';
    }

    private function tag(Automation $automation, Organization $organization, ?Customer $customer): string
    {
        if (! $customer) {
            throw new SkipRun('No customer to tag.');
        }
        $tag = Tag::withoutGlobalScopes()->where('organization_id', $organization->id)->find((int) $automation->config('tag_id'));
        if (! $tag) {
            throw new SkipRun('The tag no longer exists.');
        }
        if ($automation->action === 'add_tag') {
            $customer->tags()->syncWithoutDetaching([$tag->id]);

            return 'Tagged '.$customer->fullName().' "'.$tag->name.'".';
        }
        $customer->tags()->detach($tag->id);

        return 'Removed "'.$tag->name.'" from '.$customer->fullName().'.';
    }

    private function setStatus(Automation $automation, ?Customer $customer): string
    {
        $status = CustomerStatus::tryFrom((string) $automation->config('status'));
        if (! $customer || ! $status) {
            throw new SkipRun('No customer, or the status no longer exists.');
        }
        $customer->forceFill(['status' => $status])->save();

        return $customer->fullName().' is now '.mb_strtolower($status->label()).'.';
    }

    /** @return array<string, string> */
    private function values(Automation $automation, Organization $organization, Model $subject, ?Customer $customer): array
    {
        $base = $subject instanceof Appointment ? $this->messages->values($subject) : [
            'first_name' => $customer?->first_name ?: 'there',
            'business' => BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->value('display_name') ?: $organization->name,
            'service' => '', 'date' => '', 'time' => '', 'address' => '', 'phone' => '',
        ];
        $reason = match (true) {
            $subject instanceof CallLog => (string) $subject->reason_for_call,
            $subject instanceof Escalation => (string) $subject->reason,
            $subject instanceof Task => (string) $subject->title,
            default => '',
        };
        $links = app(LinkPage::class);

        return $base + [
            'review_link' => (string) $automation->config('review_url', ''),
            'booking_link' => $links->isLive($organization) ? route('link-page', $organization->slug) : '',
            'reason' => str_replace(['-', '_'], ' ', $reason),
        ];
    }
}
