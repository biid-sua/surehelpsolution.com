<x-mail::message>
# {{ class_basename($class) }}

{{ $message ?: '(no message)' }}

- **Where:** `{{ $where }}`
- **Request:** `{{ $url }}`
- **User id:** {{ $userId ?? 'guest' }}
- **Server time:** {{ now()->toDateTimeString() }} UTC · {{ config('app.env') }}

**Trace**

@foreach ($trace as $line)
`{{ $line }}`<br>
@endforeach

The same error is emailed at most once every {{ config('ops.alert_throttle_minutes') }} minutes. Full details are in storage/logs.
</x-mail::message>
