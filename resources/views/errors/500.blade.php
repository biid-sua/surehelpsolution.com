<!DOCTYPE html>
{{-- Self-contained on purpose: it must render even if the app or its assets are broken. --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Something went wrong · SureHelp Solution</title>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#fff;background:radial-gradient(60rem 30rem at 85% -10%,rgba(99,102,241,.35),transparent 60%),linear-gradient(180deg,#0b0c22,#06071a);padding:24px;text-align:center}
        p.k{letter-spacing:.18em;text-transform:uppercase;font-size:12px;font-weight:600;color:#8ef5c2;margin:0}h1{font-size:clamp(28px,5vw,44px);margin:16px 0 0;font-weight:600;letter-spacing:-.02em}p.l{color:#cbd5e1;max-width:34rem;margin:20px auto 0;font-size:18px;line-height:1.6}
        a{display:inline-block;margin-top:32px;background:#55eba0;color:#06071a;padding:12px 22px;border-radius:999px;font-weight:600;text-decoration:none}a:focus-visible{outline:2px solid #fff;outline-offset:3px}
    </style>
</head>
<body>
    <main>
        <p class="k">Error {{ isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500 }}</p>
        <h1>Something went wrong on our side</h1>
        <p class="l">We've been notified and we're on it. Please try again in a moment. If you need us now, call {{ config('marketing.phone', '+1 (858) 321-3947') }}.</p>
        <a href="/">Back to the home page</a>
    </main>
</body>
</html>
