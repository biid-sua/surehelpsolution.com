Hi {{ \Illuminate\Support\Str::before(trim($enquiry->name).' ', ' ') }},

Your SureHelp demo is booked for {{ $label }}.

It's a 30-minute walkthrough with our team: how calls are answered in your business's name, booking into your calendar, and what you'd see in the portal and app. Bring any questions.

Where: {{ $location }}

The invitation is attached; open it to add the demo to your calendar. Need a different time? Just reply to this email.

The SureHelp team
{{ config('app.url') }}
