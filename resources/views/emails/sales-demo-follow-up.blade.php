@switch($kind)
@case('reminder_day')
Hi {{ $first }},

A quick reminder: your SureHelp demo is tomorrow, {{ $label }}.

@if ($meetingUrl)
Join on {{ \Illuminate\Support\Str::before($location, ': ') }}: {{ $meetingUrl }}
@else
Where: {{ $location }}
@endif

It takes 30 minutes. If you can, bring a rough idea of how many calls you get in a week and when you miss them most.

Can't make it? Pick another time or cancel here: {{ $manageUrl }}
@break
@case('reminder_hour')
Hi {{ $first }},

Your SureHelp demo starts in about an hour, at {{ $label }}.

@if ($meetingUrl)
Join on {{ \Illuminate\Support\Str::before($location, ': ') }}: {{ $meetingUrl }}
@else
Where: {{ $location }}
@endif

Running late or need another time? {{ $manageUrl }}
@break
@case('recap')
Hi {{ $first }},

Thanks for joining the SureHelp demo{{ $owner ? ' with '.$owner : '' }}. Here's what we covered:

- Live receptionists answer every call, 24/7, in your business's name.
- Jobs are booked straight into your calendar, and anyone you miss gets a text back.
- Every call, booking and message is in one place, on the web and in the app.

Your next step: start your free trial. Setup takes a few minutes and you can be live in 48 hours.
{{ $trialUrl }}

Questions? Just reply to this email.
@break
@case('missed')
Hi {{ $first }},

We were ready for your SureHelp demo on {{ $label }}, but we didn't see you. No problem: these things happen.

Pick a new time that suits you here: {{ $manageUrl }}

Or, if you'd rather explore on your own, start a free trial: {{ $trialUrl }}
@break
@case('nudge')
Hi {{ $first }},

It's been a few days since your SureHelp demo. Every call you miss is a job that may go to someone else.

Your free trial is a few minutes away: {{ $trialUrl }}

Want us to set it up with you? Reply to this email and we'll book a quick call.
@break
@default
{{ $headline }}

{{ $enquiry->name }}{{ $enquiry->company ? ' · '.$enquiry->company : '' }}
{{ $enquiry->email }}{{ $enquiry->phone ? ' · '.$enquiry->phone : '' }}
@if ($enquiry->demo_status === 'booked' && $label)
New time: {{ $label }}
@endif

Open it in Admin › Website enquiries: {{ route('admin.enquiries', ['enquiry' => $enquiry->id]) }}
@endswitch

The SureHelp team
{{ config('app.url') }}
