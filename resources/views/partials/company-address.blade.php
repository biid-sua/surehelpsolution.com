@php
    $line1 = config('company.address.line1');
    $line2 = config('company.address.line2');
@endphp

@if (($format ?? 'inline') === 'legal')
<p class="contact-address-entry">
    <i class="fas fa-map-marker-alt me-2" aria-hidden="true"></i>
    <span>
        <strong>Address:</strong><br>
        {{ $line1 }}<br>
        {{ $line2 }}
    </span>
</p>
@else
<span class="company-address-lines">
    {{ $line1 }}<br>
    {{ $line2 }}
</span>
@endif
