<?php

namespace App\Enums;

enum AgentAssignmentSource: string
{
    /** Created by the tenancy migration so service continues unchanged (docs/decisions.md D3). */
    case Migration = 'migration';

    /** Created automatically because config('tenancy.auto_assign_agents') is on. */
    case Automatic = 'automatic';

    /** Created deliberately by an admin or supervisor. */
    case Manual = 'manual';
}
