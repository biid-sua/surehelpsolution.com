Hi {{ \Illuminate\Support\Str::before(trim($enquiry->name).' ', ' ') }},

@if ($kind === 'cancelled')
Your SureHelp demo on {{ $label }} is cancelled. The calendar entry is attached to remove it.

Whenever you'd like to talk, pick a new time here: {{ $manageUrl }}
@else
@if ($kind === 'moved')
Your SureHelp demo has moved to {{ $label }}.
@else
Your SureHelp demo is booked for {{ $label }}.
@endif

It's a 30-minute walkthrough with our team: how calls are answered in your business's name, booking into your calendar, and what you'd see in the portal and app. Bring any questions.

@if ($meetingUrl)
Join on {{ \Illuminate\Support\Str::before($location, ': ') }}: {{ $meetingUrl }}
@else
Where: {{ $location }}
@endif

The invitation is attached; open it to add the demo to your calendar. Need a different time, or can't make it? Change or cancel here: {{ $manageUrl }}
@endif

The SureHelp team
{{ config('app.url') }}
