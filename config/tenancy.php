<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic agent assignment
    |--------------------------------------------------------------------------
    |
    | Off by default (D40): agents only serve companies an authorized supervisor
    | or administrator assigns them to. When on, new organizations are assigned
    | to all active agents and new agents to all active organizations (the old
    | shared-pool model of D3), recorded with source "automatic" for review.
    |
    */

    'auto_assign_agents' => (bool) env('TENANCY_AUTO_ASSIGN_AGENTS', false),

];
