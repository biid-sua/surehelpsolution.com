<?php

namespace App\Events;

use App\Models\AgentAssignment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An agent started serving a company (now, or when a scheduled assignment began). Agent University
 * listens to assign the company's required training (spec §3, D43).
 */
class AgentAssignmentStarted
{
    use Dispatchable;

    public function __construct(public readonly AgentAssignment $assignment) {}
}
