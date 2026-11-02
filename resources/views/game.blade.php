{{-- "Hands Full", the missed-call game (D122). The page is a frame: the game runs in resources/js/game.js,
     its branding and buttons come from config/game.php. --}}
@php
    $c = $game['brand']['colors'];
    $vars = "--ink:{$c['ink']};--paper:{$c['paper']};--accent:{$c['accent']};--accent-ink:{$c['accent_ink']};--loss:{$c['loss']};--highlight:{$c['highlight']}";
    $icon = fn (string $name, string $class = '') => \App\Support\Ui\SiteIcons::svg($name, $class);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @section('title', $game['title'].' | '.$game['brand']['name'])
    @section('description', $game['tagline'])
    @include('partials.site-head')
    <meta name="theme-color" content="{{ $c['paper'] }}">
    <link rel="icon" href="{{ asset('assets/img/favicon.png') }}">
    @vite(['resources/css/game.css', 'resources/js/game.js'])
</head>
<body class="hg" style="{{ $vars }}">
<header class="hg-top">
    <a href="{{ route('home') }}" class="hg-logo" aria-label="{{ $game['brand']['name'] }} home"><img src="{{ asset($game['brand']['logo']) }}" alt="{{ $game['brand']['name'] }}" width="134" height="34"></a>
    <div class="hg-top-r">
        <button type="button" class="hg-icon-btn" data-mute aria-pressed="false" aria-label="Sound on">
            <svg class="on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11"/></svg>
            <svg class="off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="m16 9.5 5 5M21 9.5l-5 5"/></svg>
        </button>
        <a class="hg-btn hg-btn-ghost hg-btn-sm" href="{{ $settings['cta'][0]['url'] }}">{{ $settings['cta'][0]['label'] }}</a>
    </div>
</header>

<main id="game" class="hg-main" data-settings='@json($settings)'>
    {{-- Intro --}}
    <section class="hg-screen hg-intro is-active" data-screen="intro" aria-labelledby="hg-title">
        <div class="hg-intro-card">
            <span class="hg-eyebrow">A 60-second game</span>
            <h1 id="hg-title">Hands <span class="hg-serif">full.</span></h1>
            <p class="hg-lead">{{ $game['tagline'] }}</p>
            <ol class="hg-how">
                <li><span class="hg-n">1</span><span><b>Fix the leaks.</b> Tap each drip to tighten it. Every finished job pays.</span></li>
                <li><span class="hg-n">2</span><span><b>Mind the phone.</b> Answer and the leaks keep coming. Ignore it and that customer calls someone else.</span></li>
                <li><span class="hg-n">3</span><span><b>Get help.</b> {{ $game['brand']['name'] }} can answer for you. Your call.</span></li>
            </ol>
            <button type="button" class="hg-btn hg-btn-primary hg-btn-lg" data-start>Start the shift {!! $icon('arrow') !!}</button>
            <p class="hg-fine">Works with a mouse, a finger or the keyboard. Money in the game is made up.</p>
        </div>
    </section>

    {{-- Play --}}
    <section class="hg-screen hg-play" data-screen="play" aria-label="Game">
        <div class="hg-hud">
            <div class="hg-stat hg-earn"><span>Earned</span><b data-earned>$0</b></div>
            <div class="hg-stat hg-lose"><span>Lost</span><b data-lost>$0</b></div>
            <div class="hg-stat"><span>Missed calls</span><b data-missed>0</b></div>
            <div class="hg-mode" data-mode>On your own</div>
            <div class="hg-time" aria-label="Time left"><i data-timebar></i><span data-time>60</span></div>
        </div>

        <div class="hg-stage">
            {{-- The job: an under-sink repair --}}
            <div class="hg-scene" data-scene>
                <div class="hg-job"><span data-job-name>Kitchen sink leak</span><b data-job-value>$220</b><div class="hg-job-bar"><i data-job-bar></i></div></div>
                <svg class="hg-svg" viewBox="0 0 600 440" role="img" aria-label="Pipes under a kitchen sink">
                    <defs>
                        <linearGradient id="hg-pipe" x1="0" x2="1"><stop offset="0" stop-color="#c9ced8"/><stop offset=".5" stop-color="#f2f4f7"/><stop offset="1" stop-color="#aab1bd"/></linearGradient>
                        <linearGradient id="hg-wood" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#e9dcc6"/><stop offset="1" stop-color="#dccbb0"/></linearGradient>
                    </defs>
                    <rect x="0" y="0" width="600" height="440" fill="url(#hg-wood)"/>
                    <rect x="20" y="20" width="560" height="400" rx="14" fill="#f6efe2" stroke="#cdb995" stroke-width="3"/>
                    <path d="M120 20 h360 v70 a30 30 0 0 1 -30 30 h-300 a30 30 0 0 1 -30 -30z" fill="#e7ebf0" stroke="#b8c0cc" stroke-width="3"/>
                    <rect x="285" y="118" width="30" height="70" fill="url(#hg-pipe)" stroke="#97a0ad" stroke-width="2"/>
                    <path d="M300 186 v64 a46 46 0 0 0 92 0 v-20" fill="none" stroke="#97a0ad" stroke-width="34" stroke-linecap="round"/>
                    <path d="M300 186 v64 a46 46 0 0 0 92 0 v-20" fill="none" stroke="url(#hg-pipe)" stroke-width="28" stroke-linecap="round"/>
                    <path d="M392 230 v-36 h150" fill="none" stroke="#97a0ad" stroke-width="34" stroke-linejoin="round"/>
                    <path d="M392 230 v-36 h150" fill="none" stroke="url(#hg-pipe)" stroke-width="28" stroke-linejoin="round"/>
                    <path d="M180 120 v140 h-60 v120" fill="none" stroke="#b48a5a" stroke-width="12" stroke-linejoin="round"/>
                    <path d="M420 120 v40 h70 v200" fill="none" stroke="#5a8fb4" stroke-width="12" stroke-linejoin="round"/>
                    <rect x="104" y="372" width="34" height="22" rx="4" fill="#8e98a6"/><rect x="474" y="352" width="34" height="22" rx="4" fill="#8e98a6"/>
                    <ellipse class="hg-puddle" data-puddle cx="300" cy="404" rx="0" ry="0" fill="#5aa9e6" opacity=".55"/>
                    <g data-leaks></g>
                </svg>
                <div class="hg-busy" data-busy hidden><span>You’re on the phone.</span><small>The leaks keep coming…</small></div>
                <div class="hg-toasts" data-toasts aria-live="polite"></div>
            </div>

            {{-- The phone (on your own) / the receptionist (with Sure Help) --}}
            <aside class="hg-side">
                <div class="hg-phone" data-phone>
                    <div class="hg-phone-idle" data-phone-idle>{!! $icon('phone') !!}<span>No calls right now</span></div>
                    <div class="hg-call" data-call hidden>
                        <div class="hg-ring" data-ring-wrap><svg viewBox="0 0 44 44" aria-hidden="true"><circle cx="22" cy="22" r="20" class="hg-ring-bg"/><circle cx="22" cy="22" r="20" class="hg-ring-fg" data-ring/></svg><span data-caller-initials>JM</span></div>
                        <b data-caller-name>Jamie M.</b>
                        <span class="hg-tag" data-caller-tag>Burst pipe</span>
                        <p class="hg-say" data-caller-line hidden></p>
                        <div class="hg-call-btns" data-call-btns>
                            <button type="button" class="hg-round hg-decline" data-decline aria-label="Decline call (D)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
                            <button type="button" class="hg-round hg-answer" data-answer aria-label="Answer call (A)">{!! $icon('phone') !!}</button>
                        </div>
                        <div class="hg-choices" data-choices hidden></div>
                    </div>
                </div>

                <div class="hg-ava" data-ava hidden>
                    <div class="hg-ava-top">
                        <svg class="hg-ava-svg" data-ava-face viewBox="0 0 120 120" role="img" aria-label="{{ $game['brand']['receptionist'] }}, the receptionist">
                            <circle cx="60" cy="60" r="58" fill="#e3fbea"/>
                            <path d="M22 118c4-24 18-36 38-36s34 12 38 36z" fill="#121633"/>
                            <path d="M50 80h20v10a10 10 0 0 1-20 0z" fill="#f1c6a6"/>
                            <path d="M30 58c0-24 13-38 30-38s30 14 30 38c0 14-4 26-8 32H38c-4-6-8-18-8-32z" fill="#3a2418"/>
                            <ellipse cx="60" cy="58" rx="22" ry="26" fill="#f6d2b6"/>
                            <path d="M38 50c4-14 14-20 24-20 9 0 18 5 22 18-10-6-26-8-46 2z" fill="#3a2418"/>
                            <g class="hg-eyes"><ellipse cx="51" cy="58" rx="2.6" ry="3.2" fill="#121633"/><ellipse cx="69" cy="58" rx="2.6" ry="3.2" fill="#121633"/></g>
                            <path d="M46 52q5-3 10 0M64 52q5-3 10 0" stroke="#3a2418" stroke-width="1.6" fill="none" stroke-linecap="round"/>
                            <ellipse class="hg-mouth" cx="60" cy="72" rx="6" ry="2.4" fill="#b4473f"/>
                            <circle cx="47" cy="68" r="4" fill="#f4a7a0" opacity=".45"/><circle cx="73" cy="68" r="4" fill="#f4a7a0" opacity=".45"/>
                            <path d="M34 60c0-18 11-30 26-30s26 12 26 30" stroke="#121633" stroke-width="4" fill="none"/>
                            <rect x="28" y="56" width="10" height="16" rx="4" fill="#121633"/><rect x="82" y="56" width="10" height="16" rx="4" fill="#121633"/>
                            <path d="M33 70c2 8 8 10 16 10" stroke="#121633" stroke-width="3" fill="none" stroke-linecap="round"/><circle cx="50" cy="80" r="3.4" fill="#57ff8d"/>
                        </svg>
                        <div><b>{{ $game['brand']['receptionist'] }} is answering</b><span data-ava-status>Ready for your calls</span></div>
                    </div>
                    <div class="hg-ava-talk" data-ava-talk aria-live="polite"></div>
                    <div class="hg-urgent" data-urgent hidden>
                        <b data-urgent-text>Urgent: burst pipe</b>
                        <button type="button" class="hg-btn hg-btn-primary hg-btn-sm" data-take>Take it (T)</button>
                    </div>
                    <ul class="hg-feed" data-feed></ul>
                </div>
            </aside>
        </div>

        {{-- The offer --}}
        <div class="hg-modal" data-offer hidden role="dialog" aria-modal="true" aria-labelledby="hg-offer-title">
            <div class="hg-modal-card">
                <span class="hg-eyebrow">Ring ring</span>
                <h2 id="hg-offer-title">Want {{ $game['brand']['name'] }} to answer for you?</h2>
                <p data-offer-text></p>
                <div class="hg-ava-mini">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.2 4.2L19 7"/></svg>
                    <span>{{ $game['brand']['receptionist'] }} answers in your business’s name, books the jobs and puts urgent calls through to you.</span>
                </div>
                <div class="hg-modal-btns">
                    <button type="button" class="hg-btn hg-btn-primary" data-accept>Yes, let {{ $game['brand']['receptionist'] }} answer</button>
                    <button type="button" class="hg-btn hg-btn-ghost" data-decline-offer>Not yet</button>
                </div>
            </div>
        </div>
    </section>

    {{-- Results --}}
    <section class="hg-screen hg-results" data-screen="results" aria-labelledby="hg-res-title">
        <div class="hg-res-card">
            <span class="hg-eyebrow">Shift over</span>
            <h2 id="hg-res-title" data-res-title>Here’s how your shift went</h2>
            <p class="hg-lead" data-res-lead></p>
            <div class="hg-res-grid">
                <div class="hg-res-col" data-res-solo>
                    <h3>On your own</h3>
                    <dl><div><dt>Earned per minute</dt><dd class="g" data-r="solo-earn">$0</dd></div><div><dt>Lost per minute</dt><dd class="r" data-r="solo-lost">$0</dd></div><div><dt>Calls missed</dt><dd data-r="solo-missed">0</dd></div><div><dt>Jobs finished</dt><dd data-r="solo-jobs">0</dd></div></dl>
                </div>
                <div class="hg-res-col hg-res-help" data-res-help>
                    <h3>With {{ $game['brand']['name'] }}</h3>
                    <dl><div><dt>Earned per minute</dt><dd class="g" data-r="help-earn">$0</dd></div><div><dt>Lost per minute</dt><dd class="r" data-r="help-lost">$0</dd></div><div><dt>Calls answered by {{ $game['brand']['receptionist'] }}</dt><dd data-r="help-calls">0</dd></div><div><dt>Jobs finished</dt><dd data-r="help-jobs">0</dd></div></dl>
                </div>
            </div>
            <div class="hg-res-btns">
                <a class="hg-btn hg-btn-primary hg-btn-lg" href="{{ $settings['cta'][0]['url'] }}">{{ $settings['cta'][0]['label'] }} {!! $icon('arrow') !!}</a>
                <a class="hg-btn hg-btn-ghost hg-btn-lg" href="{{ $settings['cta'][1]['url'] }}">{{ $settings['cta'][1]['label'] }}</a>
            </div>
            <div class="hg-res-small">
                <button type="button" class="hg-link" data-again>Play again</button>
                <button type="button" class="hg-link" data-share>Share the game</button>
            </div>
            <p class="hg-fine">A game: the money and calls are made up for fun. Real receptionists, 24/7, in your business’s name, at {{ parse_url(url('/'), PHP_URL_HOST) }}.</p>
        </div>
    </section>

    <noscript><p class="hg-noscript">This game needs JavaScript. <a href="{{ route('home') }}">See how {{ $game['brand']['name'] }} answers your calls</a>.</p></noscript>
</main>
</body>
</html>
