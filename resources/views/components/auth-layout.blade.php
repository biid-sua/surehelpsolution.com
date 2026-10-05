@props(['title', 'heading' => null, 'description' => null, 'width' => 'max-w-md'])
{{-- Standalone account pages (sign in, two-step code, password reset, terms, invitations). --}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · SureHelp</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full">
    <main class="flex min-h-full items-center justify-center px-4 py-12">
        <div class="w-full {{ $width }}">
            <a href="{{ route('home') }}" class="block"><img src="{{ asset('assets/img/logo.png') }}" alt="SureHelp Solutions" class="mx-auto h-9 w-auto"></a>

            <x-ui.card class="mt-8">
                <h1 class="text-xl font-semibold text-ink">{{ $heading ?? $title }}</h1>
                @if ($description)<p class="mt-1 text-sm text-muted">{{ $description }}</p>@endif

                @if (session('status'))
                    <x-ui.alert tone="success" class="mt-5">{{ session('status') }}</x-ui.alert>
                @endif
                @if ($errors->any())
                    <x-ui.alert tone="danger" class="mt-5">
                        @if ($errors->count() === 1)
                            {{ $errors->first() }}
                        @else
                            <ul class="list-disc pl-4">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                        @endif
                    </x-ui.alert>
                @endif

                {{ $slot }}
            </x-ui.card>

            @isset($footer)
                <div class="mt-6 text-center text-sm text-muted">{{ $footer }}</div>
            @endisset
        </div>
    </main>
    @livewireScripts
</body>
</html>
