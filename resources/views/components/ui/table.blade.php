{{-- Responsive frame for data tables: scrolls horizontally on small screens, never the page. --}}
<div {{ $attributes->merge(['class' => 'overflow-x-auto']) }}>
    <table class="sh-table">
        {{ $slot }}
    </table>
</div>
