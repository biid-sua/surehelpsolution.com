<?php

/*
| Colour themes (D59).
|
| `portal`: the client portal, agent workspace, admin console and account pages. Each key matches
| a `[data-theme="…"]` block in resources/css/themes.css (Midnight is the base palette in
| resources/css/app.css). `system` follows the device: Light by day, Midnight by night.
| A person's own choice is stored on their account; everyone else sees the default an
| administrator picks under Admin › Appearance (falling back to `default` below).
|
| `site`: the public website. Visitors pick one and it is remembered in their browser only
| (localStorage); keys match `[data-site-theme="…"]` blocks in public/assets/css/site-themes.css.
|
| `swatch`: the colours shown in the pickers (background, surface, brand, accent).
*/

return [

    'default' => 'midnight',

    'portal' => [
        'midnight' => ['label' => 'Midnight', 'description' => 'Deep navy with indigo, the SureHelp classic.', 'mode' => 'dark', 'swatch' => ['#0b0c22', '#191b3f', '#818cf8', '#c084fc']],
        'dark' => ['label' => 'Dark', 'description' => 'Neutral graphite, easy on the eyes.', 'mode' => 'dark', 'swatch' => ['#0e0f12', '#1d2027', '#818cf8', '#c084fc']],
        'light' => ['label' => 'Light', 'description' => 'Bright and clean, for well-lit rooms.', 'mode' => 'light', 'swatch' => ['#f4f5fa', '#ffffff', '#4f46e5', '#9333ea']],
        'ocean' => ['label' => 'Ocean', 'description' => 'Deep sea blue with cyan.', 'mode' => 'dark', 'swatch' => ['#061420', '#11283b', '#22d3ee', '#60a5fa']],
        'forest' => ['label' => 'Forest', 'description' => 'Dark green with emerald.', 'mode' => 'dark', 'swatch' => ['#07130e', '#13291f', '#34d399', '#a3e635']],
        'sunset' => ['label' => 'Sunset', 'description' => 'Warm plum with orange and rose.', 'mode' => 'dark', 'swatch' => ['#170d14', '#2c1824', '#fb923c', '#fb7185']],
        'system' => ['label' => 'Match my device', 'description' => 'Light when your device is in light mode, Midnight when it is dark.', 'mode' => 'auto', 'swatch' => ['#f4f5fa', '#0b0c22', '#4f46e5', '#818cf8']],
    ],

    'site' => [
        'indigo' => ['label' => 'Indigo', 'swatch' => ['#0f0f23', '#4f46e5', '#7c3aed']],
        'ocean' => ['label' => 'Ocean', 'swatch' => ['#04162a', '#0891b2', '#2563eb']],
        'emerald' => ['label' => 'Emerald', 'swatch' => ['#05150f', '#059669', '#0d9488']],
        'sunset' => ['label' => 'Sunset', 'swatch' => ['#1a0b12', '#ea580c', '#e11d48']],
        'rose' => ['label' => 'Rose', 'swatch' => ['#1a0a16', '#db2777', '#9333ea']],
        'graphite' => ['label' => 'Graphite', 'swatch' => ['#0d0e11', '#475569', '#334155']],
    ],

];
