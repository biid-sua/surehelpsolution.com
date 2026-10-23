@foreach ($introLines as $line)
{{ $line }}

@endforeach
@if ($actionUrl)
{{ $actionText }}: {{ $actionUrl }}

@endif
@foreach ($outroLines as $line)
{{ $line }}

@endforeach
-- 
{{ $brand['name'] }}
