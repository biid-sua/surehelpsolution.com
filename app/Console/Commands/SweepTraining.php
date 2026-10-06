<?php

namespace App\Console\Commands;

use App\Models\TrainingAssignment;
use App\Models\TrainingCertificate;
use App\Notifications\CertificationActivity;
use App\Notifications\TrainingActivity;
use App\Support\Audit\Audit;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Training reminders (brief §1.12, §1.14): required training due within two days, overdue
 * training (to the agent and once to whoever gave it), and certifications expiring within 30 days
 * or expired. Each reminder is claimed with a conditional update, so it's sent once even if runs
 * overlap. Statuses themselves never wait for this: they follow from the dates.
 */
class SweepTraining extends Command
{
    protected $signature = 'training:sweep';

    protected $description = 'Send training due, overdue and certification expiry reminders';

    public function handle(Audit $audit): int
    {
        $now = now();
        $counts = ['due_soon' => 0, 'overdue' => 0, 'expiring' => 0, 'expired' => 0];
        $open = fn (Builder $q) => $q->whereIn('status', [TrainingAssignment::ASSIGNED, TrainingAssignment::IN_PROGRESS])
            ->whereHas('agent', fn (Builder $q) => $q->where('is_active', true))
            ->whereHas('course', fn (Builder $q) => $q->where('is_active', true));

        TrainingAssignment::query()->where($open)->where('is_required', true)->whereNull('due_soon_notified_at')
            ->whereBetween('due_at', [$now, $now->copy()->addHours(48)])->with('agent', 'course.organization', 'organization')
            ->each(function (TrainingAssignment $a) use ($now, &$counts) {
                if ($this->claim($a, 'due_soon_notified_at', $now)) {
                    $a->agent->notify(new TrainingActivity($a, TrainingActivity::DUE_SOON));
                    $counts['due_soon']++;
                }
            });

        TrainingAssignment::query()->where($open)->whereNull('overdue_notified_at')->where('due_at', '<', $now)
            ->with('agent', 'assigner', 'course.organization', 'organization')
            ->each(function (TrainingAssignment $a) use ($now, $audit, &$counts) {
                if (! $this->claim($a, 'overdue_notified_at', $now)) {
                    return;
                }
                $a->agent->notify(new TrainingActivity($a, TrainingActivity::OVERDUE));
                if ($a->is_required && $a->assigner && $a->assigner->is_active && $a->assigner->id !== $a->agent_user_id) {
                    $a->assigner->notify(new TrainingActivity($a, TrainingActivity::AGENT_OVERDUE));
                }
                $audit->record('training.overdue', $a->course, [], ['agent' => $a->agent->name, 'due' => $a->due_at?->toDateString()],
                    $a->organization, null, $a->course->title.' · '.$a->agent->name);
                $counts['overdue']++;
            });

        $active = fn (Builder $q) => $q->where('status', TrainingCertificate::ACTIVE)->whereHas('agent', fn (Builder $q) => $q->where('is_active', true));

        TrainingCertificate::query()->where($active)->whereNull('expiring_notified_at')
            ->whereBetween('expires_at', [$now, $now->copy()->addDays(TrainingCertificate::EXPIRING_DAYS)])->with('agent', 'course')
            ->each(function (TrainingCertificate $c) use ($now, &$counts) {
                if ($this->claim($c, 'expiring_notified_at', $now)) {
                    $c->agent->notify(new CertificationActivity($c, CertificationActivity::EXPIRING));
                    $counts['expiring']++;
                }
            });

        TrainingCertificate::query()->where($active)->whereNull('expired_notified_at')->where('expires_at', '<=', $now)->with('agent', 'course')
            ->each(function (TrainingCertificate $c) use ($now, $audit, &$counts) {
                if (! $this->claim($c, 'expired_notified_at', $now)) {
                    return;
                }
                // A newer certificate for the same course already replaced it: nothing to tell.
                $renewed = TrainingCertificate::query()->where('agent_user_id', $c->agent_user_id)->where('course_id', $c->course_id)
                    ->where('id', '>', $c->id)->where('status', TrainingCertificate::ACTIVE)->exists();
                if (! $renewed) {
                    $c->agent->notify(new CertificationActivity($c, CertificationActivity::EXPIRED));
                    $audit->record('training.certificate_expired', $c, [], ['agent' => $c->agent->name, 'number' => $c->number], $c->course->organization, null, $c->name);
                    $counts['expired']++;
                }
            });

        $this->info(collect($counts)->map(fn (int $n, string $k) => "{$k}: {$n}")->implode(', '));

        return self::SUCCESS;
    }

    /** Marks the reminder as sent unless another run already did. */
    private function claim(TrainingAssignment|TrainingCertificate $model, string $column, \DateTimeInterface $now): bool
    {
        return $model->newQuery()->whereKey($model->getKey())->whereNull($column)->update([$column => $now]) === 1;
    }
}
