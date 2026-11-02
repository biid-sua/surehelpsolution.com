{{-- "Hands Full", the missed-call game (D122). The page is a frame: the game runs in resources/js/game.js,
     its branding and buttons come from config/game.php. --}}
@php
    $c = $game['brand']['colors'];
    $vars = "--ink:{$c['ink']};--paper:{$c['paper']};--accent:{$c['accent']};--accent-ink:{$c['accent_ink']};--loss:{$c['loss']};--highlight:{$c['highlight']}";
    $icon = fn (string $name, string $class = '') => \App\Support\Ui\SiteIcons::svg($name, $class);
    $r = $game['brand']['receptionist'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    @section('title', $game['title'].' | '.$game['brand']['name'])
    @section('description', $game['tagline'])
    @include('partials.site-head')
    <meta name="theme-color" content="#0d1030">
    <link rel="icon" href="{{ asset('assets/img/favicon.png') }}">
    @vite(['resources/css/game.css', 'resources/js/game.js'])
</head>
<body class="hg" style="{{ $vars }}">
<canvas class="hg-fx" data-fx aria-hidden="true"></canvas>

<header class="hg-top">
    <a href="{{ route('home') }}" class="hg-logo" aria-label="{{ $game['brand']['name'] }} home"><img src="{{ asset($game['brand']['logo_dark'] ?? $game['brand']['logo']) }}" alt="{{ $game['brand']['name'] }}" width="134" height="34"></a>
    <div class="hg-top-r">
        <button type="button" class="hg-icon-btn" data-mute aria-pressed="false" aria-label="Sound on">
            <svg class="on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11"/></svg>
            <svg class="off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9.5h3.5L12 5.5v13l-4.5-4H4z"/><path d="m16 9.5 5 5M21 9.5l-5 5"/></svg>
        </button>
        <a class="hg-btn hg-btn-glass hg-btn-sm" href="{{ $settings['cta'][0]['url'] }}">{{ $settings['cta'][0]['label'] }}</a>
    </div>
</header>

<main id="game" class="hg-main" data-settings='@json($settings)'>

    {{-- ===================== Intro ===================== --}}
    <section class="hg-screen hg-intro is-active" data-screen="intro" aria-labelledby="hg-title">
        <div class="hg-intro-copy">
            <span class="hg-kicker"><i></i> 60-second challenge</span>
            <h1 id="hg-title" class="hg-title"><span>Hands</span> <span class="alt">Full</span></h1>
            <p class="hg-lead">{{ $game['tagline'] }}</p>
            <div class="hg-how">
                <div><span class="hg-how-ico w">{!! $icon('bolt') !!}</span><b>Fix the leaks</b><small>Tap the drips. Chain fast fixes for combo bonuses.</small></div>
                <div><span class="hg-how-ico p">{!! $icon('phone') !!}</span><b>Mind the phone</b><small>Answer and the leaks keep coming. Ignore it and the job walks.</small></div>
                <div><span class="hg-how-ico g">{!! $icon('users') !!}</span><b>Call in {{ $r }}</b><small>{{ $game['brand']['name'] }} can answer for you. Your call.</small></div>
            </div>
            <div class="hg-intro-cta">
                <button type="button" class="hg-btn hg-btn-play" data-start><span>Start the shift</span>{!! $icon('arrow') !!}</button>
                <span class="hg-best" data-best hidden>Best: <b data-best-val>$0</b></span>
            </div>
            <p class="hg-fine">Mouse, touch or keyboard. Sound on for the full experience. Money in the game is made up.</p>
        </div>
        <div class="hg-intro-art" aria-hidden="true">
            <div class="hg-orbit"></div>
            <svg class="hg-hero-svg" viewBox="0 0 420 420">
                <defs>
                    <linearGradient id="hp-body" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="#2b3270"/><stop offset="1" stop-color="#151a45"/></linearGradient>
                    <linearGradient id="hp-screen" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#6d5efc"/><stop offset="1" stop-color="#2a1f8f"/></linearGradient>
                    <linearGradient id="hp-pipe" x1="0" x2="1"><stop offset="0" stop-color="#8b95a8"/><stop offset=".45" stop-color="#f4f7fb"/><stop offset="1" stop-color="#7d879a"/></linearGradient>
                </defs>
                <path d="M40 120 h120 a40 40 0 0 1 40 40 v60" fill="none" stroke="#5a6478" stroke-width="40" stroke-linecap="round"/>
                <path d="M40 120 h120 a40 40 0 0 1 40 40 v60" fill="none" stroke="url(#hp-pipe)" stroke-width="32" stroke-linecap="round"/>
                <circle cx="200" cy="222" r="22" fill="#ff4d6d" opacity=".25" class="hg-hero-pulse"/>
                <g class="hg-hero-drips"><path d="M200 246 q-8 13 0 19 q8 -6 0 -19z" fill="#4fc3ff"/><path d="M190 246 q-6 10 0 14 q6 -4 0 -14z" fill="#4fc3ff"/><path d="M210 246 q-6 10 0 14 q6 -4 0 -14z" fill="#4fc3ff"/></g>
                <ellipse cx="200" cy="380" rx="90" ry="12" fill="#4fc3ff" opacity=".35" class="hg-hero-puddle"/>
                <g class="hg-hero-phone">
                    <rect x="236" y="96" width="138" height="250" rx="26" fill="url(#hp-body)" stroke="#7b84ff" stroke-width="3"/>
                    <rect x="248" y="112" width="114" height="218" rx="16" fill="url(#hp-screen)"/>
                    <circle cx="305" cy="190" r="30" fill="#fff" opacity=".18"/><circle cx="305" cy="190" r="22" fill="#57ff8d"/>
                    <path d="M296 182h5l2.5 6-3 2a14 14 0 0 0 7.5 7.5l2-3 6 2.5v5a1.5 1.5 0 0 1-1.5 1.5A20 20 0 0 1 294.5 183.5 1.5 1.5 0 0 1 296 182z" fill="#121633"/>
                    <rect x="270" y="240" width="70" height="10" rx="5" fill="#fff" opacity=".8"/><rect x="282" y="258" width="46" height="8" rx="4" fill="#fff" opacity=".45"/>
                    <circle cx="276" cy="300" r="14" fill="#ff4d6d"/><circle cx="334" cy="300" r="14" fill="#22c55e"/>
                </g>
                <g class="hg-hero-waves" fill="none" stroke="#57ff8d" stroke-width="4" stroke-linecap="round"><path d="M392 150a40 40 0 0 1 0 60"/><path d="M406 132a64 64 0 0 1 0 96"/></g>
                <g class="hg-hero-coins"><circle cx="90" cy="300" r="18" fill="#ffd166" stroke="#e0a400" stroke-width="4"/><text x="90" y="307" text-anchor="middle" font-size="20" font-weight="800" fill="#9a6a00">$</text>
                <circle cx="60" cy="240" r="12" fill="#ffd166" stroke="#e0a400" stroke-width="3"/></g>
            </svg>
        </div>
    </section>

    {{-- ===================== Play ===================== --}}
    <section class="hg-screen hg-play" data-screen="play" aria-label="Game">
        <div class="hg-hud">
            <div class="hg-stat hg-earn"><span class="hg-coin" aria-hidden="true">$</span><div><small>Earned</small><b data-earned>$0</b></div></div>
            <div class="hg-stat hg-lose"><span class="hg-coin bad" aria-hidden="true">$</span><div><small>Lost</small><b data-lost>$0</b></div></div>
            <div class="hg-stat hg-miss"><span class="hg-coin ph" aria-hidden="true">{!! $icon('phone') !!}</span><div><small>Missed</small><b data-missed>0</b></div></div>
            <div class="hg-combo" data-combo><small>Combo</small><b data-combo-val>x1</b><i data-combo-bar></i></div>
            <div class="hg-mode" data-mode>Solo shift</div>
            <div class="hg-timer" aria-label="Time left"><svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="20" class="bg"/><circle cx="24" cy="24" r="20" class="fg" data-timer-ring/></svg><b data-time>60</b></div>
        </div>

        <div class="hg-stage" data-stage>
            <div class="hg-scene" data-scene>
                <div class="hg-job"><div><small>Current job</small><span data-job-name>Kitchen sink leak</span></div><b data-job-value>$220</b><div class="hg-job-bar"><i data-job-bar></i></div></div>
                <div class="hg-svg-wrap">
                    <svg class="hg-svg" data-svg viewBox="0 0 800 500" role="img" aria-label="Pipes under a kitchen sink">
                        <defs>
                            <pattern id="hg-tiles" width="56" height="56" patternUnits="userSpaceOnUse"><rect width="56" height="56" fill="#1b2240"/><rect x="2" y="2" width="52" height="52" rx="4" fill="#212a4d"/></pattern>
                            <linearGradient id="hg-chrome" x1="0" x2="1"><stop offset="0" stop-color="#6f788b"/><stop offset=".35" stop-color="#e9eef5"/><stop offset=".55" stop-color="#ffffff"/><stop offset="1" stop-color="#7c8699"/></linearGradient>
                            <linearGradient id="hg-chromeV" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#6f788b"/><stop offset=".4" stop-color="#f4f7fb"/><stop offset="1" stop-color="#7c8699"/></linearGradient>
                            <linearGradient id="hg-wood" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#3a2a22"/><stop offset="1" stop-color="#261a14"/></linearGradient>
                            <linearGradient id="hg-door" x1="0" x2="1"><stop offset="0" stop-color="#e3a764"/><stop offset="1" stop-color="#b9783d"/></linearGradient>
                            <linearGradient id="hg-door2" x1="1" x2="0"><stop offset="0" stop-color="#e3a764"/><stop offset="1" stop-color="#b9783d"/></linearGradient>
                            <linearGradient id="hg-steel" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#c9d1dc"/><stop offset="1" stop-color="#8a95a6"/></linearGradient>
                            <linearGradient id="hg-water" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#5fd0ff" stop-opacity=".85"/><stop offset="1" stop-color="#1f6fd1" stop-opacity=".9"/></linearGradient>
                            <linearGradient id="hg-bucket" x1="0" x2="1"><stop offset="0" stop-color="#2f7de0"/><stop offset=".5" stop-color="#6fb2ff"/><stop offset="1" stop-color="#2560b5"/></linearGradient>
                            <radialGradient id="hg-light" cx=".42" cy=".38" r=".62"><stop offset="0" stop-color="#fff6d8" stop-opacity=".55"/><stop offset=".55" stop-color="#fff6d8" stop-opacity=".12"/><stop offset="1" stop-color="#000" stop-opacity="0"/></radialGradient>
                            <linearGradient id="hg-shade" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#000" stop-opacity=".45"/><stop offset=".25" stop-color="#000" stop-opacity="0"/></linearGradient>
                        </defs>
                        <rect width="800" height="500" fill="url(#hg-tiles)"/>
                        <rect x="80" y="40" width="640" height="440" fill="url(#hg-wood)"/>
                        <g opacity=".18" stroke="#000" stroke-width="2"><path d="M80 130h640M80 230h640M80 330h640M80 430h640"/></g>
                        {{-- doors --}}
                        <path d="M0 18 L80 40 L80 480 L0 500z" fill="url(#hg-door)"/><path d="M14 60 L66 72 L66 440 L14 456z" fill="none" stroke="#8c5528" stroke-width="3" opacity=".6"/><rect x="58" y="240" width="8" height="46" rx="4" fill="#d9dde3"/>
                        <path d="M800 18 L720 40 L720 480 L800 500z" fill="url(#hg-door2)"/><path d="M786 60 L734 72 L734 440 L786 456z" fill="none" stroke="#8c5528" stroke-width="3" opacity=".6"/><rect x="734" y="240" width="8" height="46" rx="4" fill="#d9dde3"/>
                        <path d="M80 480 L720 480 L800 500 L0 500z" fill="#1a120d"/>
                        {{-- sink basin underside --}}
                        <path d="M170 40 h460 v40 a34 34 0 0 1 -34 34 h-392 a34 34 0 0 1 -34 -34z" fill="url(#hg-steel)"/>
                        <path d="M190 92 h420" stroke="#fff" stroke-opacity=".35" stroke-width="3"/>
                        <rect x="366" y="112" width="48" height="16" rx="6" fill="url(#hg-chrome)"/>
                        {{-- supply lines and valves --}}
                        <path d="M300 112 C300 220 210 260 210 380" fill="none" stroke="#5c6475" stroke-width="16" stroke-linecap="round"/>
                        <path d="M300 112 C300 220 210 260 210 380" fill="none" stroke="#c8ced8" stroke-width="12" stroke-linecap="round" stroke-dasharray="3 3"/>
                        <path d="M500 112 C500 220 590 260 590 380" fill="none" stroke="#5c6475" stroke-width="16" stroke-linecap="round"/>
                        <path d="M500 112 C500 220 590 260 590 380" fill="none" stroke="#c8ced8" stroke-width="12" stroke-linecap="round" stroke-dasharray="3 3"/>
                        <rect x="194" y="378" width="32" height="44" rx="8" fill="url(#hg-chrome)"/><rect x="574" y="378" width="32" height="44" rx="8" fill="url(#hg-chrome)"/>
                        <ellipse cx="210" cy="372" rx="26" ry="10" fill="#e5484d" stroke="#9b1c23" stroke-width="3"/><ellipse cx="590" cy="372" rx="26" ry="10" fill="#3b82f6" stroke="#1e3a8a" stroke-width="3"/>
                        {{-- drain: tailpiece, P-trap, wall --}}
                        <rect x="373" y="126" width="34" height="104" fill="url(#hg-chrome)"/>
                        <rect x="366" y="168" width="48" height="16" rx="6" fill="url(#hg-chrome)" stroke="#5c6475" stroke-width="2"/>
                        <path d="M390 228 v40 a52 52 0 0 0 104 0 v-30" fill="none" stroke="#5c6475" stroke-width="42" stroke-linecap="round"/>
                        <path d="M390 228 v40 a52 52 0 0 0 104 0 v-30" fill="none" stroke="url(#hg-chrome)" stroke-width="34" stroke-linecap="round"/>
                        <path d="M494 238 v-26 h226" fill="none" stroke="#5c6475" stroke-width="42" stroke-linejoin="round"/>
                        <path d="M494 238 v-26 h226" fill="none" stroke="url(#hg-chromeV)" stroke-width="34" stroke-linejoin="round"/>
                        <rect x="366" y="222" width="48" height="16" rx="6" fill="url(#hg-chrome)" stroke="#5c6475" stroke-width="2"/>
                        <rect x="470" y="226" width="48" height="16" rx="6" fill="url(#hg-chrome)" stroke="#5c6475" stroke-width="2"/>
                        <rect x="700" y="186" width="20" height="52" rx="6" fill="url(#hg-chromeV)" stroke="#5c6475" stroke-width="2"/>
                        {{-- bucket and toolbox --}}
                        <path d="M104 392 h76 l-8 86 h-60z" fill="url(#hg-bucket)"/><ellipse cx="142" cy="392" rx="38" ry="8" fill="#1d4f9a"/><path d="M106 392 q36 -46 72 0" fill="none" stroke="#9aa3b2" stroke-width="3"/>
                        <rect x="612" y="430" width="96" height="48" rx="6" fill="#e5484d"/><rect x="612" y="430" width="96" height="14" rx="6" fill="#c2343a"/><rect x="644" y="420" width="32" height="12" rx="4" fill="none" stroke="#222" stroke-width="4"/>
                        {{-- water --}}
                        <path data-water d="M80 480 L720 480 L720 480 L80 480z" fill="url(#hg-water)"/>
                        <path data-water-hl d="" fill="none" stroke="#bff0ff" stroke-width="2" stroke-opacity=".7"/>
                        <rect x="80" y="40" width="640" height="440" fill="url(#hg-shade)" pointer-events="none"/>
                        <rect class="hg-flash-light" x="0" y="0" width="800" height="500" fill="url(#hg-light)" pointer-events="none"/>
                        <g data-leaks></g>
                        <g data-wrenches></g>
                    </svg>
                    <div class="hg-flood" data-flood hidden><b>Flood warning!</b><span>Fix the leaks</span></div>
                </div>
                <div class="hg-busy" data-busy hidden><div><b>You’re on the phone</b><span>The leaks keep coming…</span></div></div>
                <div class="hg-toasts" data-toasts aria-live="polite"></div>
            </div>

            <aside class="hg-side">
                {{-- the phone (on your own) --}}
                <div class="hg-phone" data-phone>
                    <div class="hg-phone-notch"></div>
                    <div class="hg-phone-status"><span data-clock>9:41</span><span class="bars"><i></i><i></i><i></i><i></i></span></div>
                    <div class="hg-phone-idle" data-phone-idle>
                        <b data-clock-big>9:41</b><span>No new calls</span>
                        <div class="hg-lock-card">{!! $icon('bolt') !!}<span>Fix leaks while it’s quiet</span></div>
                    </div>
                    <div class="hg-call" data-call hidden>
                        <span class="hg-call-label" data-call-label>Incoming call</span>
                        <div class="hg-ring" data-ring-wrap>
                            <i class="w1"></i><i class="w2"></i><i class="w3"></i>
                            <svg viewBox="0 0 44 44" aria-hidden="true"><circle cx="22" cy="22" r="20" class="hg-ring-bg"/><circle cx="22" cy="22" r="20" class="hg-ring-fg" data-ring/></svg>
                            <span class="hg-avatar" data-caller-avatar><span data-caller-initials>JM</span></span>
                        </div>
                        <b class="hg-caller" data-caller-name>Jamie M.</b>
                        <span class="hg-tag" data-caller-tag>Burst pipe</span>
                        <span class="hg-value" data-caller-value>~$450 job</span>
                        <p class="hg-say" data-caller-line hidden></p>
                        <div class="hg-call-btns" data-call-btns>
                            <div><button type="button" class="hg-round hg-decline" data-decline aria-label="Decline call (D)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button><small>Ignore <kbd>D</kbd></small></div>
                            <div><button type="button" class="hg-round hg-answer" data-answer aria-label="Answer call (A)">{!! $icon('phone') !!}</button><small>Answer <kbd>A</kbd></small></div>
                        </div>
                        <div class="hg-choices" data-choices hidden></div>
                    </div>
                </div>

                {{-- the receptionist (with Sure Help) --}}
                <div class="hg-ava" data-ava hidden>
                    <div class="hg-ava-head"><span class="hg-live"><i></i>{{ $game['brand']['name'] }} active</span><span class="hg-ava-count" data-ava-count>0 calls</span></div>
                    <div class="hg-ava-top">
                        <div class="hg-ava-ring">
                            <svg class="hg-ava-svg" data-ava-face viewBox="0 0 160 160" role="img" aria-label="{{ $r }}, the receptionist">
                                <defs>
                                    <radialGradient id="av-bg" cx=".5" cy=".4" r=".7"><stop offset="0" stop-color="#3a3f9e"/><stop offset="1" stop-color="#1a1d55"/></radialGradient>
                                    <linearGradient id="av-hair" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#5a3420"/><stop offset="1" stop-color="#2d170c"/></linearGradient>
                                    <linearGradient id="av-skin" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#ffdcc0"/><stop offset="1" stop-color="#f1b98f"/></linearGradient>
                                </defs>
                                <circle cx="80" cy="80" r="78" fill="url(#av-bg)"/>
                                <g class="av-body">
                                    <path d="M14 164c6-34 28-52 66-52s60 18 66 52z" fill="#57ff8d"/>
                                    <path d="M58 116l22 20 22-20" fill="#fff"/><path d="M80 136v28" stroke="#2fbf6a" stroke-width="3"/>
                                    <path d="M66 100h28v14a14 14 0 0 1-28 0z" fill="#f1b98f"/>
                                </g>
                                <g class="av-head">
                                    <path d="M36 82c0-34 18-54 44-54s44 20 44 54c0 22-6 40-12 48H48c-6-8-12-26-12-48z" fill="url(#av-hair)"/>
                                    <ellipse cx="80" cy="80" rx="30" ry="35" fill="url(#av-skin)"/>
                                    <path d="M48 70c6-22 20-30 34-30 14 0 26 8 30 28-14-10-38-12-64 2z" fill="url(#av-hair)"/>
                                    <g class="av-eyes"><ellipse cx="68" cy="81" rx="3.6" ry="4.4" fill="#1b1d3b"/><ellipse cx="92" cy="81" rx="3.6" ry="4.4" fill="#1b1d3b"/><circle cx="69.2" cy="79.6" r="1.2" fill="#fff"/><circle cx="93.2" cy="79.6" r="1.2" fill="#fff"/></g>
                                    <path d="M60 72q8-5 15 0M85 72q8-5 15 0" stroke="#3a1f12" stroke-width="2.4" fill="none" stroke-linecap="round"/>
                                    <circle cx="62" cy="93" r="6" fill="#ff8f8f" opacity=".35"/><circle cx="98" cy="93" r="6" fill="#ff8f8f" opacity=".35"/>
                                    <path class="av-smile" d="M71 97q9 8 18 0" stroke="#b4473f" stroke-width="3" fill="none" stroke-linecap="round"/>
                                    <ellipse class="av-mouth" cx="80" cy="99" rx="7" ry="4" fill="#9b2f2f"/>
                                    <path d="M42 84c0-28 16-44 38-44s38 16 38 44" stroke="#14163a" stroke-width="5" fill="none"/>
                                    <rect x="34" y="78" width="13" height="22" rx="6" fill="#14163a"/><rect x="113" y="78" width="13" height="22" rx="6" fill="#14163a"/>
                                    <path d="M40 98c3 12 12 15 24 15" stroke="#14163a" stroke-width="4" fill="none" stroke-linecap="round"/>
                                    <circle class="av-mic" cx="66" cy="113" r="4.5" fill="#57ff8d"/>
                                </g>
                            </svg>
                        </div>
                        <div class="hg-ava-id"><b>{{ $r }}</b><span data-ava-status>Ready for your calls</span><span class="hg-typing" data-typing hidden><i></i><i></i><i></i></span></div>
                    </div>
                    <div class="hg-ava-talk" data-ava-talk aria-live="polite"></div>
                    <div class="hg-urgent" data-urgent hidden>
                        <div><small>Urgent call</small><b data-urgent-text>Burst pipe</b></div>
                        <button type="button" class="hg-btn hg-btn-hot hg-btn-sm" data-take>Take it <kbd>T</kbd></button>
                        <i class="hg-urgent-bar" data-urgent-bar></i>
                    </div>
                    <ul class="hg-feed" data-feed></ul>
                </div>
            </aside>
        </div>

        <div class="hg-pops" data-pops aria-hidden="true"></div>
        <div class="hg-vignette" data-vignette aria-hidden="true"></div>
        <div class="hg-countdown" data-countdown hidden aria-live="assertive"><b data-count>3</b></div>

        <div class="hg-modal" data-offer hidden role="dialog" aria-modal="true" aria-labelledby="hg-offer-title">
            <div class="hg-modal-card">
                <div class="hg-modal-burst" aria-hidden="true">{!! $icon('phone') !!}</div>
                <span class="hg-kicker"><i></i> Power-up available</span>
                <h2 id="hg-offer-title">Let {{ $game['brand']['name'] }} answer for you?</h2>
                <div class="hg-offer-stats" data-offer-stats></div>
                <p>{{ $r }} answers in your business’s name, books the jobs, screens sales calls and puts urgent calls through to you.</p>
                <div class="hg-modal-btns">
                    <button type="button" class="hg-btn hg-btn-play" data-accept><span>Yes, bring in {{ $r }}</span>{!! $icon('arrow') !!}</button>
                    <button type="button" class="hg-btn hg-btn-glass" data-decline-offer>I’ll manage</button>
                </div>
            </div>
        </div>
    </section>

    {{-- ===================== Results ===================== --}}
    <section class="hg-screen hg-results" data-screen="results" aria-labelledby="hg-res-title">
        <div class="hg-res-card">
            <div class="hg-stars" data-stars aria-label="Rating">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2.8 2.8 5.7 6.3.9-4.5 4.4 1 6.2L12 17.1 6.4 20l1-6.2L2.9 9.4l6.3-.9Z"/></svg>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2.8 2.8 5.7 6.3.9-4.5 4.4 1 6.2L12 17.1 6.4 20l1-6.2L2.9 9.4l6.3-.9Z"/></svg>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2.8 2.8 5.7 6.3.9-4.5 4.4 1 6.2L12 17.1 6.4 20l1-6.2L2.9 9.4l6.3-.9Z"/></svg>
            </div>
            <span class="hg-kicker"><i></i> Shift over</span>
            <h2 id="hg-res-title" data-res-title tabindex="-1">Here’s how your shift went</h2>
            <div class="hg-score"><small>Total earned</small><b data-score>$0</b><span class="hg-newbest" data-newbest hidden>New best!</span></div>
            <p class="hg-lead" data-res-lead></p>
            <div class="hg-bars">
                <div class="hg-bar-row"><span>On your own</span><div class="hg-bar"><i class="solo" data-bar-solo></i></div><b data-r="solo-earn">$0</b></div>
                <div class="hg-bar-row" data-res-help><span>With {{ $game['brand']['name'] }}</span><div class="hg-bar"><i class="help" data-bar-help></i></div><b data-r="help-earn">$0</b></div>
                <small class="hg-bars-note">Money earned per minute of the shift</small>
            </div>
            <div class="hg-chips">
                <div><small>Lost per min, solo</small><b class="r" data-r="solo-lost">$0</b></div>
                <div data-res-help-chip><small>Lost per min, with {{ $r }}</small><b class="g" data-r="help-lost">$0</b></div>
                <div><small>Calls missed</small><b data-r="missed">0</b></div>
                <div><small>Calls {{ $r }} answered</small><b data-r="help-calls">0</b></div>
                <div><small>Jobs finished</small><b data-r="jobs">0</b></div>
                <div><small>Best combo</small><b data-r="combo">x1</b></div>
            </div>
            <div class="hg-res-btns">
                <a class="hg-btn hg-btn-play" href="{{ $settings['cta'][0]['url'] }}"><span>{{ $settings['cta'][0]['label'] }}</span>{!! $icon('arrow') !!}</a>
                <a class="hg-btn hg-btn-glass" href="{{ $settings['cta'][1]['url'] }}">{{ $settings['cta'][1]['label'] }}</a>
            </div>
            <div class="hg-res-small">
                <button type="button" class="hg-link" data-again>{!! $icon('refresh') !!} Play again</button>
                <button type="button" class="hg-link" data-share>{!! $icon('globe') !!} Share the game</button>
            </div>
            <p class="hg-fine">A game: the money and calls are made up for fun. Real receptionists answer for real businesses, 24/7, in their name.</p>
        </div>
    </section>

    <noscript><p class="hg-noscript">This game needs JavaScript. <a href="{{ route('home') }}">See how {{ $game['brand']['name'] }} answers your calls</a>.</p></noscript>
</main>
</body>
</html>
