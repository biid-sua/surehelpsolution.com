<?php

namespace App\Services\Automation;

use App\Actions\Customers\RecordTimelineEvent;
use App\Actions\Notifications\NotifyOrganization;
use App\Actions\Tasks\CreateTask;
use App\Enums\AppointmentStatus;
use App\Enums\CustomerStatus;
use App\Enums\TaskType;
use App\Enums\TimelineEventType;
use App\Models\Appointment;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Organization;
use App\Notifications\AutomationNotice;
use App\Notifications\CustomerEmail;
use App\Services\Billing\FeatureAccess;
use App\Services\Messages\CustomerMessages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;

/**
 * Runs businesses' automations (spec §42–43, D50).
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
    ) {}

    /** @return int runs scheduled */
    public function fire(string $trigger, Appointment|Customer $subject): int
    {
        $automations = Automation::withoutGlobalScopes()->where('organization_id', $subject->organization_id)
            ->where('trigger', $trigger)->where('is_active', true)->get();
        $scheduled = 0;
        foreach ($automations as $automation) {
            if ($this->conditionsHold($automation, $subject) !== null) {
                continue;
            }
            $scheduled += AutomationRun::withoutGlobalScopes()->insertOrIgnore([
                'automation_id' => $automation->id,
                'organization_id' => $automation->organization_id,
                'subject_type' => $subject instanceof Appointment ? 'appointment' : 'customer',
                'subject_id' => $subject->id,
                'status' => 'pending',
                'run_at' => now()->addMinutes($automation->delay_minutes),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $scheduled;
    }

    /** @return array{done: int, skipped: int, failed: int} */
    public function runDue(int $limit = 200): array
    {
        $counts = ['done' => 0, 'skipped' => 0, 'failed' => 0];
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
            ! $subject => 'The appointment or customer no longer exists.',
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
        if ($subject instanceof Appointment) {
            $expected = ['appointment_completed' => AppointmentStatus::Completed, 'appointment_cancelled' => AppointmentStatus::Cancelled][$automation->trigger] ?? null;
            if ($expected && $subject->status !== $expected) {
                return 'The appointment is now '.mb_strtolower($subject->status->label()).'.';
            }
            $services = array_map('intval', (array) $automation->condition('service_ids', []));
            if ($services !== [] && ! in_array((int) $subject->service_id, $services, true)) {
                return 'A different service.';
            }
        }

        return null;
    }

    private function customerOf(Model $subject): ?Customer
    {
        return $subject instanceof Customer ? $subject : ($subject instanceof Appointment ? $subject->customer : null);
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

    /** @return array<string, string> */
    private function values(Automation $automation, Organization $organization, Model $subject, ?Customer $customer): array
    {
        $base = $subject instanceof Appointment ? $this->messages->values($subject) : [
            'first_name' => $customer?->first_name ?: 'there',
            'business' => BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->value('display_name') ?: $organization->name,
            'service' => '', 'date' => '', 'time' => '', 'address' => '', 'phone' => '',
        ];

        return $base + ['review_link' => (string) $automation->config('review_url', '')];
    }
}
