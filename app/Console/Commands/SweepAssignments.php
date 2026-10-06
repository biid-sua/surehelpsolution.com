<?php

namespace App\Console\Commands;

use App\Events\AgentAssignmentStarted;
use App\Models\AgentAssignment;
use App\Notifications\AssignmentActivity;
use App\Support\Audit\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps assignment labels in step with their dates and tells agents (D40). Access never depends on
 * this: "current" is decided in the query. Each change is claimed with a conditional update, so
 * overlapping runs act once.
 */
class SweepAssignments extends Command
{
    protected $signature = 'assignments:sweep';

    protected $description = 'Start scheduled agent assignments and end expired ones';

    public function handle(Audit $audit): int
    {
        $started = 0;
        $ended = 0;

        AgentAssignment::query()->where('status', AgentAssignment::SCHEDULED)->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->with(['agent', 'organization'])->each(function (AgentAssignment $a) use ($audit, &$started) {
                if (DB::table('agent_assignments')->where('id', $a->id)->where('status', AgentAssignment::SCHEDULED)->update(['status' => AgentAssignment::ACTIVE, 'status_changed_at' => now()]) === 1) {
                    $a->refresh();
                    $audit->record('agent.assignment_started', $a, old: ['status' => 'scheduled'], new: ['status' => 'active'], organization: $a->organization, label: ($a->agent->name ?? 'Agent').' → '.($a->organization->name ?? 'company'));
                    $a->agent?->notify(new AssignmentActivity($a, AssignmentActivity::STARTED));
                    AgentAssignmentStarted::dispatch($a);
                    $started++;
                }
            });

        AgentAssignment::query()->whereIn('status', [AgentAssignment::SCHEDULED, AgentAssignment::ACTIVE, AgentAssignment::SUSPENDED])
            ->whereNotNull('ends_at')->where('ends_at', '<=', now())
            ->with(['agent', 'organization'])->each(function (AgentAssignment $a) use ($audit, &$ended) {
                $old = $a->status;
                if (DB::table('agent_assignments')->where('id', $a->id)->where('status', $old)->update(['status' => AgentAssignment::ENDED, 'end_reason' => $a->end_reason ?? 'The planned end date was reached.', 'status_changed_at' => now()]) === 1) {
                    $a->refresh();
                    $audit->record('agent.assignment_expired', $a, old: ['status' => $old], new: ['status' => 'ended'], organization: $a->organization, label: ($a->agent->name ?? 'Agent').' → '.($a->organization->name ?? 'company'));
                    if ($old !== AgentAssignment::SCHEDULED) {
                        $a->agent?->notify(new AssignmentActivity($a, AssignmentActivity::ENDED));
                    }
                    $ended++;
                }
            });

        $this->info("{$started} assignment(s) started, {$ended} ended.");

        return self::SUCCESS;
    }
}
