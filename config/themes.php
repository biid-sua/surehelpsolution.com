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
| `colors`: the same palette as plain values for the mobile app (GET /api/v1/themes). They must match
| the CSS tokens; ThemesTest checks that. Colours with transparency are #RRGGBBAA.
*/

return [

    'default' => 'midnight',

    'portal' => [
        'midnight' => ['label' => 'Midnight', 'description' => 'Deep navy with indigo, the SureHelp classic.', 'mode' => 'dark', 'swatch' => ['#0b0c22', '#191b3f', '#818cf8', '#c084fc'],
            'colors' => ['canvas' => '#0b0c22', 'surface' => '#121331', 'surface_2' => '#191b3f', 'surface_3' => '#22254f', 'line' => '#FFFFFF14', 'line_strong' => '#FFFFFF29', 'ink' => '#eef0fa', 'muted' => '#a9adcf', 'subtle' => '#8a8fb8', 'brand' => '#818cf8', 'brand_strong' => '#4f46e5', 'accent' => '#a855f7', 'success' => '#34d399', 'warning' => '#fbbf24', 'danger' => '#f87171', 'info' => '#60a5fa'],
        ],
        'dark' => ['label' => 'Dark', 'description' => 'Neutral graphite, easy on the eyes.', 'mode' => 'dark', 'swatch' => ['#0e0f12', '#1d2027', '#818cf8', '#c084fc'],
            'colors' => ['canvas' => '#0e0f12', 'surface' => '#16181d', 'surface_2' => '#1d2027', 'surface_3' => '#262a33', 'line' => '#FFFFFF14', 'line_strong' => '#FFFFFF29', 'ink' => '#ecedf1', 'muted' => '#a8adba', 'subtle' => '#8b91a0', 'brand' => '#818cf8', 'brand_strong' => '#4f46e5', 'accent' => '#a855f7', 'success' => '#34d399', 'warning' => '#fbbf24', 'danger' => '#f87171', 'info' => '#60a5fa'],
        ],
        'light' => ['label' => 'Light', 'description' => 'Bright and clean, for well-lit rooms.', 'mode' => 'light', 'swatch' => ['#f4f5fa', '#ffffff', '#4f46e5', '#9333ea'],
            'colors' => ['canvas' => '#f4f5fa', 'surface' => '#ffffff', 'surface_2' => '#f0f1f8', 'surface_3' => '#e3e5f1', 'line' => '#0F172A1A', 'line_strong' => '#0F172A2E', 'ink' => '#161a33', 'muted' => '#474c6e', 'subtle' => '#5e6386', 'brand' => '#4f46e5', 'brand_strong' => '#4f46e5', 'accent' => '#a855f7', 'success' => '#047857', 'warning' => '#b45309', 'danger' => '#b91c1c', 'info' => '#1d4ed8'],
        ],
        'ocean' => ['label' => 'Ocean', 'description' => 'Deep sea blue with cyan.', 'mode' => 'dark', 'swatch' => ['#061420', '#11283b', '#22d3ee', '#60a5fa'],
            'colors' => ['canvas' => '#061420', 'surface' => '#0b1e2e', 'surface_2' => '#11283b', 'surface_3' => '#183349', 'line' => '#BAE6FD17', 'line_strong' => '#BAE6FD2E', 'ink' => '#e8f4fb', 'muted' => '#a3c0d2', 'subtle' => '#84a5ba', 'brand' => '#22d3ee', 'brand_strong' => '#0e7490', 'accent' => '#3b82f6', 'success' => '#34d399', 'warning' => '#fbbf24', 'danger' => '#f87171', 'info' => '#60a5fa'],
        ],
        'forest' => ['label' => 'Forest', 'description' => 'Dark green with emerald.', 'mode' => 'dark', 'swatch' => ['#07130e', '#13291f', '#34d399', '#a3e635'],
            'colors' => ['canvas' => '#07130e', 'surface' => '#0d1f17', 'surface_2' => '#13291f', 'surface_3' => '#1a3428', 'line' => '#D1FAE514', 'line_strong' => '#D1FAE52B', 'ink' => '#e9f6ef', 'muted' => '#a6c6b5', 'subtle' => '#87a998', 'brand' => '#34d399', 'brand_strong' => '#047857', 'accent' => '#84cc16', 'success' => '#34d399', 'warning' => '#fbbf24', 'danger' => '#f87171', 'info' => '#60a5fa'],
        ],
        'sunset' => ['label' => 'Sunset', 'description' => 'Warm plum with orange and rose.', 'mode' => 'dark', 'swatch' => ['#170d14', '#2c1824', '#fb923c', '#fb7185'],
            'colors' => ['canvas' => '#170d14', 'surface' => '#22121c', 'surface_2' => '#2c1824', 'surface_3' => '#38202e', 'line' => '#FFE4E617', 'line_strong' => '#FFE4E62E', 'ink' => '#fbeef3', 'muted' => '#d6b3c1', 'subtle' => '#b8929f', 'brand' => '#fb923c', 'brand_strong' => '#c2410c', 'accent' => '#f43f5e', 'success' => '#34d399', 'warning' => '#fbbf24', 'danger' => '#f87171', 'info' => '#60a5fa'],
        ],
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
