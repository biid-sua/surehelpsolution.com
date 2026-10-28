{{-- A business's own domain that's connected but not working yet (D101). Plain, unbranded. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $host }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font: 17px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; background: Canvas; color: CanvasText; }
        main { max-width: 32rem; padding: 24px; text-align: center; }
        h1 { font-size: 1.4rem; margin: 0 0 .5rem; }
        p { margin: 0; opacity: .75; }
    </style>
</head>
<body>
    <main>
        <h1>{{ $host }}</h1>
        <p>This website is being set up. Please check back soon.</p>
    </main>
</body>
</html>
