{{-- Colour theme (D59): set before the page paints. A file, not inline, so the head is the same on every page (D106). --}}
<script src="{{ asset('js/theme-boot.js') }}?v={{ filemtime(public_path('js/theme-boot.js')) }}"></script>
