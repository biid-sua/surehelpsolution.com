{{-- One action step's settings (D81). $m is the wire path of the step, $idp a unique id prefix. --}}
@php($action = $step['action'] ?? '')
<div>
    <label for="{{ $idp }}-action" class="sr-only">Action</label>
    <select id="{{ $idp }}-action" wire:model.live="{{ $m }}.action" class="sh-input">
        @foreach ($actions as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
    </select>
    @error($m.'.action') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
</div>

@if ($action === 'send_email')
    <div>
        <label for="{{ $idp }}-subject" class="sh-label">Email subject</label>
        <input id="{{ $idp }}-subject" type="text" wire:model="{{ $m }}.subject" class="sh-input">
        @error($m.'.subject') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="{{ $idp }}-body" class="sh-label">Email</label>
        <textarea id="{{ $idp }}-body" wire:model="{{ $m }}.body" rows="7" class="sh-input font-mono text-sm"></textarea>
        <p class="mt-1 text-xs text-subtle">You can use: @foreach ($placeholders as $p)<code class="mr-1 rounded bg-surface-2 px-1">{{ '{'.$p.'}' }}</code>@endforeach</p>
        @error($m.'.body') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="{{ $idp }}-review" class="sh-label">Review link (for {review_link})</label>
        <input id="{{ $idp }}-review" type="url" wire:model="{{ $m }}.review_url" class="sh-input" placeholder="https://g.page/r/…/review">
        @error($m.'.review_url') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
    </div>
    <label class="flex items-start gap-3 rounded-lg bg-surface-2 p-3 text-sm">
        <input type="checkbox" wire:model="{{ $m }}.consent_only" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
        <span><span class="font-medium text-ink">Only customers who agreed to emails</span>
            <span class="block text-xs text-subtle">Recommended for thank-you, review and marketing emails. Others are skipped and shown in the history.</span></span>
    </label>
@elseif ($action === 'send_text')
    @unless ($texting)
        <x-ui.alert tone="warning">Texting isn't switched on for your business yet (it needs a SureHelp line and carrier approval). Until then, this step is skipped and shown in the history.</x-ui.alert>
    @endunless
    <div>
        <label for="{{ $idp }}-text" class="sh-label">Text message</label>
        <textarea id="{{ $idp }}-text" wire:model.blur="{{ $m }}.text" rows="3" maxlength="480" class="sh-input text-sm"></textarea>
        <p class="mt-1 text-xs text-subtle">Keep it short (one text is about 160 characters). You can use: @foreach ($placeholders as $p)<code class="mr-1 rounded bg-surface-2 px-1">{{ '{'.$p.'}' }}</code>@endforeach</p>
        @error($m.'.text') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
    </div>
    @if (str_contains((string) ($step['text'] ?? ''), '{review_link}'))
        <div>
            <label for="{{ $idp }}-review-t" class="sh-label">Review link (for {review_link})</label>
            <input id="{{ $idp }}-review-t" type="url" wire:model="{{ $m }}.review_url" class="sh-input" placeholder="https://g.page/r/…/review">
            @error($m.'.review_url') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
        </div>
    @endif
    @if (in_array($trigger, ['call_missed', 'call_logged', 'conversation_started'], true))
        <label class="flex items-start gap-3 rounded-lg bg-surface-2 p-3 text-sm">
            <input type="checkbox" wire:model="{{ $m }}.consent_only" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
            <span><span class="font-medium text-ink">Only customers who agreed to texts</span>
                <span class="block text-xs text-subtle">They contacted you first, so a direct reply is allowed. Keep this on for anything promotional.</span></span>
        </label>
    @else
        <p class="rounded-lg bg-surface-2 p-3 text-xs text-subtle">Sent only to customers who agreed to texts, never to numbers that texted STOP. Every text appears in the Inbox, so replies continue there.</p>
    @endif
@elseif ($action === 'send_survey')
    <div>
        <label for="{{ $idp }}-channel" class="sh-label">Send by</label>
        <select id="{{ $idp }}-channel" wire:model.live="{{ $m }}.channel" class="sh-input w-auto">
            <option value="text">Text message</option>
            <option value="email">Email</option>
        </select>
    </div>
    @if (($step['channel'] ?? 'text') === 'email')
        <div>
            <label for="{{ $idp }}-ssubject" class="sh-label">Email subject</label>
            <input id="{{ $idp }}-ssubject" type="text" wire:model="{{ $m }}.subject" class="sh-input" placeholder="How did we do, {first_name}?">
            @error($m.'.subject') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="{{ $idp }}-sbody" class="sh-label">Email</label>
            <textarea id="{{ $idp }}-sbody" wire:model="{{ $m }}.body" rows="5" class="sh-input text-sm" placeholder="Hi {first_name}, thanks for choosing {business}. How did we do? {survey_link}"></textarea>
            @error($m.'.body') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
        </div>
    @else
        @unless ($texting)
            <x-ui.alert tone="warning">Texting isn't switched on for your business yet. Until then, this step is skipped and shown in the history.</x-ui.alert>
        @endunless
        <div>
            <label for="{{ $idp }}-stext" class="sh-label">Text message</label>
            <textarea id="{{ $idp }}-stext" wire:model="{{ $m }}.text" rows="3" maxlength="480" class="sh-input text-sm"></textarea>
            <p class="mt-1 text-xs text-subtle">Customers can tap the link or simply reply with a number from 1 to 5.</p>
            @error($m.'.text') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
        </div>
    @endif
    <p class="text-xs text-subtle">Put <code class="rounded bg-surface-2 px-1">{survey_link}</code> where the rating link goes. Each customer is asked at most once every two weeks. A score of 1 to 3 creates a call-back task and tells your team.</p>
    <div>
        <label for="{{ $idp }}-sreview" class="sh-label">Google review link (offered to customers who give 4 or 5)</label>
        <input id="{{ $idp }}-sreview" type="url" wire:model="{{ $m }}.review_url" class="sh-input" placeholder="https://g.page/r/…/review">
        @error($m.'.review_url') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
    </div>
    <label class="flex items-start gap-3 rounded-lg bg-surface-2 p-3 text-sm">
        <input type="checkbox" wire:model="{{ $m }}.consent_only" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
        <span><span class="font-medium text-ink">Only customers who agreed to {{ ($step['channel'] ?? 'text') === 'email' ? 'emails' : 'texts' }}</span></span>
    </label>
@elseif ($action === 'email_address')
    <div>
        <label for="{{ $idp }}-to" class="sh-label">Email to</label>
        <input id="{{ $idp }}-to" type="email" wire:model="{{ $m }}.to" class="sh-input" placeholder="owner@yourbusiness.com">
        @error($m.'.to') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="{{ $idp }}-subject2" class="sh-label">Subject</label>
        <input id="{{ $idp }}-subject2" type="text" wire:model="{{ $m }}.subject" class="sh-input">
        @error($m.'.subject') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="{{ $idp }}-body2" class="sh-label">Email</label>
        <textarea id="{{ $idp }}-body2" wire:model="{{ $m }}.body" rows="5" class="sh-input text-sm"></textarea>
        <p class="mt-1 text-xs text-subtle">You can use: <code class="mr-1 rounded bg-surface-2 px-1">{customer}</code>@foreach ($placeholders as $p)<code class="mr-1 rounded bg-surface-2 px-1">{{ '{'.$p.'}' }}</code>@endforeach</p>
        @error($m.'.body') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
    </div>
@elseif (in_array($action, ['add_tag', 'remove_tag'], true))
    <div>
        <label for="{{ $idp }}-tag" class="sh-label">Tag</label>
        <select id="{{ $idp }}-tag" wire:model="{{ $m }}.tag_id" class="sh-input">
            <option value="">Choose a tag…</option>
            @foreach ($tags as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
        </select>
        @if ($tags->isEmpty())<p class="mt-1 text-xs text-subtle">Add tags on a customer's page first.</p>@endif
        @error($m.'.tag_id') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
    </div>
@elseif ($action === 'set_status')
    <div>
        <label for="{{ $idp }}-status" class="sh-label">New status</label>
        <select id="{{ $idp }}-status" wire:model="{{ $m }}.status" class="sh-input">
            <option value="">Choose…</option>
            @foreach ($statuses as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
        </select>
        @error($m.'.status') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
    </div>
@elseif ($action === 'create_task')
    <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_10rem]">
        <div>
            <label for="{{ $idp }}-title" class="sh-label">Task</label>
            <input id="{{ $idp }}-title" type="text" wire:model="{{ $m }}.title" class="sh-input">
            @error($m.'.title') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="{{ $idp }}-due" class="sh-label">Due after (hours)</label>
            <input id="{{ $idp }}-due" type="number" min="0" wire:model="{{ $m }}.due_hours" class="sh-input">
            @error($m.'.due_hours') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
        </div>
    </div>
@else
    <div>
        <label for="{{ $idp }}-msg" class="sh-label">Message to your team</label>
        <input id="{{ $idp }}-msg" type="text" wire:model="{{ $m }}.message" class="sh-input" maxlength="500">
        @error($m.'.message') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
    </div>
@endif
