{{ $headline }}
============================

@foreach (['name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'company' => 'Company', 'industry' => 'Industry', 'team_size' => 'Team size', 'monthly_calls' => 'Calls a month', 'current_solution' => 'Today they use', 'needs' => 'Needs', 'timeline' => 'Wants to start', 'plan' => 'Plan'] as $key => $label)
@if (filled($lead[$key] ?? null))
{{ $label }}: {{ $lead[$key] }}
@endif
@endforeach
@if ($enquiry)
Score: {{ ucfirst((string) $enquiry->score) }}
@endif
Page: {{ $chat->page ?: '/' }}@if ($chat->utm) · Campaign: {{ collect($chat->utm)->map(fn ($v, $k) => "$k=$v")->implode(', ') }}@endif


Latest messages:
@foreach ($recent as $message)
{{ ['visitor' => 'Visitor', 'assistant' => config('sales.assistant', 'Ava'), 'team' => 'Team'][$message->author] ?? $message->author }}: {{ $message->body }}
@endforeach

Open the chat and reply: {{ $link }}
