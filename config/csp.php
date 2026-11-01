<?php

/*
 * Content-Security-Policy (D67, docs/security.md): which scripts, styles, frames and connections pages
 * may use. Scripts need the per-request nonce or come from this site.
 */
return [
    // enforce: blocks and reports | report: only reports (to roll back quickly) | off: no header
    'mode' => env('CSP_MODE', 'enforce'),

    // Browsers send blocked items to /api/csp-report; they're written to the security log.
    'report' => (bool) env('CSP_REPORT', true),

    // Outside script and style hosts pages may load from. None: the public website's Bootstrap, AOS,
    // Font Awesome and Roboto are served from public/vendor (D120). Add one here only if a page needs it.
    'website_cdns' => [],

    // Training videos (Agent University) and the browser phone (Twilio Voice).
    'frames' => ['https://www.youtube-nocookie.com', 'https://player.vimeo.com'],
    'connect' => ['https://*.twilio.com', 'wss://*.twilio.com'],
];
