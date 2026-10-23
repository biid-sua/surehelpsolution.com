{{-- A business's link-in-bio page (D69): public, phone-first, no SureHelp branding beyond a footnote. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="h-full" {!! app(\App\Support\Ui\Themes::class)->htmlAttributes(null) !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page['name'] }}</title>
    @if ($page['about'])<meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags($page['about']), 155) }}">@endif
    <meta property="og:title" content="{{ $page['name'] }}">
    @if ($page['logo'])<meta property="og:image" content="{{ $page['logo'] }}">@endif
    @include('partials.theme-boot')
    @vite(['resources/css/app.css'])
    @if ($brand['white_label'])<style>{!! \App\Services\Business\Branding::shades($brand['color']) !!}</style>@endif
</head>
<body class="h-full">
    <main class="flex min-h-full justify-center px-4 py-12">
        <div class="w-full max-w-md text-center">
            @if ($page['logo'])
                <img src="{{ $page['logo'] }}" alt="" class="mx-auto size-24 rounded-full bg-white object-contain p-2 ring-1 ring-line">
            @else
                <div class="mx-auto flex size-24 items-center justify-center rounded-full bg-brand-600 text-3xl font-semibold text-white">{{ mb_strtoupper(mb_substr($page['name'], 0, 1)) }}</div>
            @endif
            <h1 class="mt-4 text-2xl font-semibold text-ink">{{ $page['name'] }}</h1>
            @if ($page['area'])<p class="mt-1 text-sm text-muted">{{ $page['area'] }}</p>@endif
            @if ($page['about'])<p class="mx-auto mt-3 max-w-sm text-sm text-muted">{{ \Illuminate\Support\Str::limit($page['about'], 280) }}</p>@endif

            <nav class="mt-8 space-y-3" aria-label="{{ __('Links') }}">
                @foreach ($page['buttons'] as $button)
                    <a href="{{ $button['href'] }}" @if (str_starts_with($button['href'], 'http')) rel="noopener" @endif
                        @class([
                            'block w-full rounded-xl px-5 py-3.5 text-base font-semibold transition',
                            'bg-brand-600 text-white hover:bg-brand-500' => $button['kind'] === 'primary',
                            'bg-surface-2 text-ink ring-1 ring-line hover:ring-brand-400' => $button['kind'] !== 'primary',
                        ])>{{ $button['label'] }}</a>
                @endforeach
            </nav>

            @unless ($brand['white_label'])
                <p class="mt-10 text-xs text-subtle">{{ __('Bookings and messages handled by SureHelp Solutions') }}</p>
            @endunless
        </div>
    </main>
    @if ($page['widget'])
        <script src="{{ route('chat.widget-js') }}" data-surehelp-chat="{{ $page['widget']->public_key }}" async></script>
    @endif
</body>
</html>
