Hi{{ $name ? ' '.\Illuminate\Support\Str::before(trim($name).' ', ' ') : '' }},

You have a reply from the SureHelp team about your chat on our website:

{{ $body }}

Reply to this email, or open the chat on {{ config('app.url') }} to carry on.

The SureHelp team
