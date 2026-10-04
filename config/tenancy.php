<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic agent assignment
    |--------------------------------------------------------------------------
    |
    | While the shared agent pool serves every client (current operating model),
    | new organizations are assigned to all active agents and new agents to all
    | active organizations. Agents can still only act inside organizations they
    | are assigned to — turn this off once ops manages assignments explicitly.
    | See docs/decisions.md D3.
    |
    */

    'auto_assign_agents' => (bool) env('TENANCY_AUTO_ASSIGN_AGENTS', true),

];
