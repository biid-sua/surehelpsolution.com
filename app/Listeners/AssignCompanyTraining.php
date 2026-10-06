<?php

namespace App\Listeners;

use App\Events\AgentAssignmentStarted;
use App\Services\Training\TrainingAssigner;

/**
 * Brief §3 / D43: when an agent starts serving a company, they get that company's training rules
 * (and any platform rules they're missing) straight away, with each rule's due period.
 */
class AssignCompanyTraining
{
    public function __construct(private readonly TrainingAssigner $assigner) {}

    public function handle(AgentAssignmentStarted $event): void
    {
        $this->assigner->onCompanyAssignment($event->assignment);
    }
}
