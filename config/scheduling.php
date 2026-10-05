<?php

return [

    // Hours with fewer agents on shift than this show as gaps in the coverage view (SUP-03).
    'min_agents' => (int) env('SCHEDULE_MIN_AGENTS', 1),

];
