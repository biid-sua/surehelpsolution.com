{{-- Search and sharing tags for every public page (D58). Pages set the title, description and, when
     needed, robots; structured data is pushed onto the "schema" stack. --}}
@php
    // Sections given as strings arrive already escaped; decode once so {{ }} escapes exactly once.
    $seoTitle = html_entity_decode(trim($__env->yieldContent('title', config('site.meta.home.title'))), ENT_QUOTES | ENT_HTML5);
    $seoDescription = html_entity_decode(trim($__env->yieldContent('description', config('site.meta.home.description'))), ENT_QUOTES | ENT_HTML5);
    $seoCanonical = url()->current();   // without the query string, so ?utm=… copies don't compete
    $seoImage = asset(config('site.share_image'));
@endphp
<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}">
<meta name="robots" content="@yield('robots', 'index, follow, max-image-preview:large, max-snippet:-1')">
<link rel="canonical" href="{{ $seoCanonical }}">

<meta property="og:type" content="@yield('og_type', 'website')">
<meta property="og:site_name" content="{{ config('site.name') }}">
<meta property="og:locale" content="en_US">
<meta property="og:title" content="{{ $seoTitle }}">
<meta property="og:description" content="{{ $seoDescription }}">
<meta property="og:url" content="{{ $seoCanonical }}">
<meta property="og:image" content="{{ $seoImage }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ config('site.name') }}: 24/7 live answering service">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="{{ config('site.x_handle') }}">
<meta name="twitter:title" content="{{ $seoTitle }}">
<meta name="twitter:description" content="{{ $seoDescription }}">
<meta name="twitter:image" content="{{ $seoImage }}">
<meta name="theme-color" content="#1E3A8A">
@stack('schema')
