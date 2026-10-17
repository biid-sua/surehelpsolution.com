<?php

/*
 * Content-Security-Policy (D67, docs/security.md): which scripts, styles, frames and connections pages
 * may use. Scripts need the per-request nonce or come from this site or the listed CDNs.
 */
return [
    // enforce: blocks and reports | report: only reports (to roll back quickly) | off: no header
    'mode' => env('CSP_MODE', 'enforce'),

    // Browsers send blocked items to /api/csp-report; they're written to the security log.
    'report' => (bool) env('CSP_REPORT', true),

    // CDNs the public website still loads Bootstrap, AOS, Font Awesome and Google Fonts from.
    'website_cdns' => ['https://cdn.jsdelivr.net', 'https://unpkg.com', 'https://cdnjs.cloudflare.com'],

    // Training videos (Agent University) and the browser phone (Twilio Voice).
    'frames' => ['https://www.youtube-nocookie.com', 'https://player.vimeo.com'],
    'connect' => ['https://*.twilio.com', 'wss://*.twilio.com'],
];
