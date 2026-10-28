{{-- A hosted business website (D99). Self-contained: its own small stylesheet, the business's colour,
     and the SureHelp snippet for booking and chat. All text is escaped; content is data, never HTML. --}}
@php
    $style = $theme['style'];
    $accent = $theme['accent'];
    $callLabel = $phone ? __('Call :phone', ['phone' => \App\Support\Phone::display($phone)]) : __('Contact us');
    $actionLabel = fn (?string $label) => filled($label) ? $label : ($action['kind'] === 'book' ? __('Book online') : $callLabel);
    $paragraphs = fn (string $text) => array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $text) ?: [])));
    $title = $page['seo_title'] ?: ($page['slug'] === '' ? $name : $page['title'].' | '.$name);
    $whiteLabel = app(\App\Services\Business\Branding::class)->for($site->organization)['white_label'] ?? false;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @if ($page['seo_description'])<meta name="description" content="{{ $page['seo_description'] }}">@endif
    @if ($preview)<meta name="robots" content="noindex">@endif
    <meta property="og:title" content="{{ $title }}">
    @if ($page['seo_description'])<meta property="og:description" content="{{ $page['seo_description'] }}">@endif
    @if ($logo)<meta property="og:image" content="{{ $logo }}">@endif
    @unless ($preview)<link rel="canonical" href="{{ $site->url($page['slug']) }}">@endunless
    <script type="application/ld+json" nonce="{{ Vite::cspNonce() }}">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
    <style>
        :root { --accent: {{ $accent }}; --accent-ink: #fff; --ink: #0f172a; --muted: #475569; --line: #e2e8f0; --bg: #ffffff; --soft: color-mix(in srgb, var(--accent) 7%, #fff);
            --radius: {{ ['bold' => '10px', 'clean' => '16px', 'warm' => '22px'][$style] }};
            --head: {{ $style === 'warm' ? 'Georgia, "Times New Roman", serif' : 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif' }};
            --weight: {{ $style === 'clean' ? 600 : 800 }}; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 17px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: {{ $style === 'warm' ? 'var(--soft)' : 'var(--bg)' }}; }
        a { color: var(--accent); }
        img { max-width: 100%; }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 0 20px; }
        h1, h2, h3 { font-family: var(--head); font-weight: var(--weight); line-height: 1.15; margin: 0 0 .5em; text-wrap: balance; }
        h1 { font-size: clamp(2.1rem, 5vw, 3.6rem); letter-spacing: -.02em; }
        h2 { font-size: clamp(1.6rem, 3vw, 2.3rem); letter-spacing: -.01em; }
        h3 { font-size: 1.15rem; }
        p { margin: 0 0 1em; }
        .preview { background: #fef3c7; color: #78350f; font-size: 14px; text-align: center; padding: 8px 12px; }
        header.top { background: var(--bg); border-bottom: 1px solid var(--line); position: sticky; top: 0; z-index: 10; }
        header.top .wrap { display: flex; align-items: center; gap: 20px; min-height: 68px; flex-wrap: wrap; }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 800; color: var(--ink); text-decoration: none; font-size: 1.1rem; }
        .brand img { height: 40px; width: auto; }
        nav.menu { display: flex; gap: 18px; flex-wrap: wrap; margin-left: auto; }
        nav.menu a { color: var(--ink); text-decoration: none; font-weight: 600; font-size: .95rem; }
        nav.menu a[aria-current="page"] { color: var(--accent); }
        .btn { display: inline-block; padding: 14px 24px; border-radius: var(--radius); background: var(--accent); color: var(--accent-ink); font-weight: 700; text-decoration: none; border: 2px solid var(--accent); }
        .btn.ghost { background: transparent; color: var(--accent); }
        .btn.small { padding: 9px 16px; font-size: .95rem; }
        section { padding: 72px 0; }
        section.alt { background: {{ $style === 'warm' ? '#fff' : 'var(--soft)' }}; }
        .hero { padding: 96px 0; {{ $style === 'bold' ? 'background: var(--accent); color: #fff;' : '' }} }
        .hero p.lead { font-size: 1.25rem; max-width: 640px; {{ $style === 'bold' ? 'color: rgba(255,255,255,.9);' : 'color: var(--muted);' }} }
        .hero .btn { {{ $style === 'bold' ? 'background: #fff; color: var(--accent); border-color: #fff;' : '' }} }
        .hero .btn.ghost { {{ $style === 'bold' ? 'background: transparent; color: #fff; border-color: rgba(255,255,255,.7);' : '' }} }
        .actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 28px; }
        .grid { display: grid; gap: 20px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); margin-top: 28px; }
        .card { background: var(--bg); border: 1px solid var(--line); border-radius: var(--radius); padding: 24px; }
        .card p { color: var(--muted); margin: 0; }
        blockquote { margin: 0; }
        blockquote p { font-size: 1.05rem; color: var(--ink); }
        blockquote footer { margin-top: 12px; color: var(--muted); font-weight: 600; }
        .stars { color: #f59e0b; letter-spacing: 2px; }
        details { border-bottom: 1px solid var(--line); padding: 16px 0; }
        summary { font-weight: 700; cursor: pointer; }
        details p { margin: 10px 0 0; color: var(--muted); }
        table.hours { border-collapse: collapse; margin-top: 16px; min-width: 280px; }
        table.hours td { padding: 6px 24px 6px 0; border-bottom: 1px solid var(--line); }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
        .chip { background: var(--bg); border: 1px solid var(--line); border-radius: 999px; padding: 6px 14px; font-weight: 600; font-size: .95rem; }
        .cta { background: var(--accent); color: #fff; text-align: center; }
        .cta p { color: rgba(255,255,255,.9); max-width: 620px; margin-left: auto; margin-right: auto; }
        .cta .btn { background: #fff; color: var(--accent); border-color: #fff; }
        dl.contact { display: grid; gap: 12px; margin: 20px 0; }
        dl.contact dt { font-weight: 700; } dl.contact dd { margin: 0; color: var(--muted); }
        footer.bottom { border-top: 1px solid var(--line); padding: 28px 0; color: var(--muted); font-size: .9rem; background: var(--bg); }
        footer.bottom .wrap { display: flex; gap: 16px; justify-content: space-between; flex-wrap: wrap; }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
        @media (max-width: 640px) {
            body { font-size: 16px; }
            header.top { position: static; }
            header.top .wrap { gap: 10px; padding-top: 10px; padding-bottom: 10px; min-height: 0; }
            header.top .btn.small { margin-left: auto; padding: 8px 12px; font-size: .85rem; }
            nav.menu { order: 3; width: 100%; margin-left: 0; flex-wrap: nowrap; overflow-x: auto; gap: 16px; padding-bottom: 2px; }
            nav.menu a { white-space: nowrap; }
            section { padding: 52px 0; } .hero { padding: 64px 0; }
        }
    </style>
</head>
<body>
    @if ($preview)
        <div class="preview">{{ __('Preview of version :n. Visitors don\'t see this until you publish it.', ['n' => $version->number]) }}</div>
    @endif
    <header class="top">
        <div class="wrap">
            <a class="brand" href="{{ $pageUrl('') }}">@if ($logo)<img src="{{ $logo }}" alt="">@endif<span>{{ $name }}</span></a>
            @if (count($pages) > 1)
                <nav class="menu" aria-label="{{ __('Pages') }}">
                    @foreach ($pages as $p)
                        <a href="{{ $pageUrl($p['slug']) }}" @if ($p['slug'] === $page['slug']) aria-current="page" @endif>{{ $p['title'] }}</a>
                    @endforeach
                </nav>
            @endif
            <a class="btn small" href="{{ $action['href'] }}" @style(['margin-left: auto' => count($pages) <= 1])>{{ $action['kind'] === 'book' ? __('Book online') : $callLabel }}</a>
        </div>
    </header>

    <main>
        @foreach ($page['sections'] as $i => $section)
            @switch($section['type'])
                @case('hero')
                    <section class="hero" id="{{ $section['id'] }}"><div class="wrap">
                        @if ($i === 0)<h1>{{ $section['heading'] ?: $name }}</h1>@else<h2>{{ $section['heading'] ?: $name }}</h2>@endif
                        @if ($section['text'])<p class="lead">{{ $section['text'] }}</p>@endif
                        <div class="actions">
                            <a class="btn" href="{{ $action['href'] }}">{{ $actionLabel($section['button']) }}</a>
                            @if ($action['kind'] === 'book' && $phone)<a class="btn ghost" href="tel:{{ $phone }}">{{ $callLabel }}</a>@endif
                        </div>
                    </div></section>
                    @break
                @case('services')
                    <section id="{{ $section['id'] }}" @class(['alt' => $i % 2 === 1])><div class="wrap">
                        <h2>{{ $section['heading'] ?: __('Our services') }}</h2>
                        @foreach ($paragraphs($section['text']) as $para)<p>{{ $para }}</p>@endforeach
                        <div class="grid">
                            @foreach ($section['items'] as $item)
                                <div class="card"><h3>{{ $item['title'] }}</h3>@if ($item['text'])<p>{{ $item['text'] }}</p>@endif</div>
                            @endforeach
                        </div>
                    </div></section>
                    @break
                @case('reviews')
                    <section id="{{ $section['id'] }}" @class(['alt' => $i % 2 === 1])><div class="wrap">
                        <h2>{{ $section['heading'] ?: __('What our customers say') }}</h2>
                        <div class="grid">
                            @foreach ($section['items'] as $item)
                                <blockquote class="card"><div class="stars" aria-hidden="true">★★★★★</div><p>“{{ $item['text'] }}”</p>@if ($item['title'])<footer>{{ $item['title'] }}</footer>@endif</blockquote>
                            @endforeach
                        </div>
                    </div></section>
                    @break
                @case('faq')
                    <section id="{{ $section['id'] }}" @class(['alt' => $i % 2 === 1])><div class="wrap" style="max-width: 820px;">
                        <h2>{{ $section['heading'] ?: __('Questions') }}</h2>
                        @foreach ($section['items'] as $item)
                            <details><summary>{{ $item['title'] }}</summary><p>{{ $item['text'] }}</p></details>
                        @endforeach
                    </div></section>
                    @break
                @case('hours')
                    <section id="{{ $section['id'] }}" @class(['alt' => $i % 2 === 1])><div class="wrap">
                        <h2>{{ $section['heading'] ?: __('Opening hours') }}</h2>
                        @if ($section['text'])<p>{{ $section['text'] }}</p>@endif
                        @if (collect($hours)->flatten()->isNotEmpty())
                            <table class="hours">
                                @foreach ($hours as $day => $times)
                                    <tr><td>{{ __($day) }}</td><td>{{ $times ? implode(', ', $times) : __('Closed') }}</td></tr>
                                @endforeach
                            </table>
                        @endif
                    </div></section>
                    @break
                @case('area')
                    <section id="{{ $section['id'] }}" @class(['alt' => $i % 2 === 1])><div class="wrap">
                        <h2>{{ $section['heading'] ?: __('Where we work') }}</h2>
                        @foreach ($paragraphs($section['text']) as $para)<p>{{ $para }}</p>@endforeach
                        @if ($section['items'])<div class="chips">@foreach ($section['items'] as $item)<span class="chip">{{ $item['title'] }}</span>@endforeach</div>@endif
                    </div></section>
                    @break
                @case('contact')
                    <section id="contact" @class(['alt' => $i % 2 === 1])><div class="wrap">
                        <h2>{{ $section['heading'] ?: __('Get in touch') }}</h2>
                        @foreach ($paragraphs($section['text']) as $para)<p>{{ $para }}</p>@endforeach
                        <dl class="contact">
                            @if ($phone)<div><dt>{{ __('Phone') }}</dt><dd><a href="tel:{{ $phone }}">{{ \App\Support\Phone::display($phone) }}</a></dd></div>@endif
                            @if ($email)<div><dt>{{ __('Email') }}</dt><dd><a href="mailto:{{ $email }}">{{ $email }}</a></dd></div>@endif
                            @if ($address)<div><dt>{{ __('Address') }}</dt><dd>{{ $address }}</dd></div>@endif
                        </dl>
                        <a class="btn" href="{{ $action['href'] }}">{{ $actionLabel($section['button']) }}</a>
                    </div></section>
                    @break
                @case('cta')
                    <section class="cta" id="{{ $section['id'] }}"><div class="wrap">
                        <h2>{{ $section['heading'] }}</h2>
                        @if ($section['text'])<p>{{ $section['text'] }}</p>@endif
                        <a class="btn" href="{{ $action['href'] }}">{{ $actionLabel($section['button']) }}</a>
                    </div></section>
                    @break
                @default
                    <section id="{{ $section['id'] }}" @class(['alt' => $i % 2 === 1])><div class="wrap" style="max-width: 820px;">
                        @if ($section['heading'])<h2>{{ $section['heading'] }}</h2>@endif
                        @foreach ($paragraphs($section['text']) as $para)<p>{{ $para }}</p>@endforeach
                        @if ($section['items'])
                            <div class="grid">@foreach ($section['items'] as $item)<div class="card"><h3>{{ $item['title'] }}</h3><p>{{ $item['text'] }}</p></div>@endforeach</div>
                        @endif
                    </div></section>
            @endswitch
        @endforeach
    </main>

    <footer class="bottom">
        <div class="wrap">
            <span>© {{ now()->year }} {{ $name }}@if ($phone) · <a href="tel:{{ $phone }}">{{ \App\Support\Phone::display($phone) }}</a>@endif</span>
            @unless ($whiteLabel)<span>{{ __('Calls answered 24/7 by SureHelp Solutions') }}</span>@endunless
        </div>
    </footer>
    @if ($widget)
        <script src="{{ route('chat.widget-js') }}" data-surehelp-chat="{{ $widget->public_key }}" async nonce="{{ Vite::cspNonce() }}"></script>
    @endif
</body>
</html>
