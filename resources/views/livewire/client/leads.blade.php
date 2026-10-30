@php
    $ago = fn (?int $s) => $s === null ? null : ($s < 60 ? $s.' s' : ($s < 3600 ? intdiv($s, 60).' min' : intdiv($s, 3600).' h '.intdiv($s % 3600, 60).' min'));
@endphp
<div>
    <x-ui.page-header title="Leads" description="People who asked you for work, from your website and lead sources like Facebook, Google, Angi and Thumbtack. The first business to answer usually wins the job." />

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat label="New leads this month" icon="sparkles" :value="number_format($stats['count'])" />
        <x-ui.stat label="Typical time to first answer" icon="clock" :value="$stats['median'] === null ? '—' : $ago($stats['median'])" hint="half answered faster than this" />
        <x-ui.stat label="Answered within a minute" icon="check-circle" :value="$stats['within_minute'] === null ? '—' : $stats['within_minute'].'%'" hint="by text or email" />
    </div>

    <div class="grid gap-6 lg:grid-cols-5">
        <div class="space-y-6 lg:col-span-3">
            <x-ui.card title="Recent leads" :padding="false">
                <ul class="divide-y divide-line">
                    @forelse ($leads as $lead)
                        <li wire:key="lead-{{ $lead->ulid }}" class="px-5 py-3 text-sm">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="font-medium text-ink">
                                    @if ($lead->customer)
                                        <a href="{{ route('app.customers.show', $lead->customer) }}" wire:navigate class="hover:underline">{{ $lead->name ?: 'Someone' }}</a>
                                    @else
                                        {{ $lead->name ?: 'Someone' }}
                                    @endif
                                    <span class="font-normal text-subtle">· {{ $lead->sourceLabel() }}</span>
                                </span>
                                <span class="text-xs text-subtle">{{ $lead->received_at->timezone($timezone)->format('M j, g:i A') }}</span>
                            </div>
                            @if ($lead->message)<p class="mt-1 line-clamp-2 text-muted">{{ $lead->message }}</p>@endif
                            <p class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                                @if ($lead->texted_at)<span class="text-emerald-300">Texted in {{ $ago(max(0, (int) $lead->received_at->diffInSeconds($lead->texted_at))) }}</span>@endif
                                @if ($lead->emailed_at)<span class="text-emerald-300">Emailed in {{ $ago(max(0, (int) $lead->received_at->diffInSeconds($lead->emailed_at))) }}</span>@endif
                                @if ($lead->call_status)<span @class(['text-emerald-300' => $lead->call_status === 'connected', 'text-amber-300' => $lead->call_status !== 'connected'])>{{ \App\Models\Lead::CALL_LABELS[$lead->call_status] ?? $lead->call_status }}</span>@endif
                                @if (! $lead->first_response_at && ! $lead->call_status)<span class="text-amber-300">No instant answer</span>@endif
                                @if ($lead->note)<span class="text-subtle">{{ ucfirst($lead->note) }}</span>@endif
                                <span class="text-subtle">{{ collect([$lead->phone ? \App\Models\PhoneNumber::pretty($lead->phone) : null, $lead->email])->filter()->implode(' · ') }}</span>
                            </p>
                        </li>
                    @empty
                        <li class="px-5 py-6"><x-ui.empty-state icon="sparkles" title="No leads yet" description="Leads from your website form appear here. Connect Facebook, Google, Angi or Thumbtack with your lead link." /></li>
                    @endforelse
                </ul>
                @if ($leads->hasPages())<div class="border-t border-line px-5 py-3">{{ $leads->links() }}</div>@endif
            </x-ui.card>
        </div>

        <div class="space-y-6 lg:col-span-2">
            <form wire:submit="save">
                <x-ui.card title="Instant answer">
                    <x-slot:actions>
                        <label class="flex items-center gap-2 text-sm font-medium text-ink">
                            <input type="checkbox" wire:model="form.is_enabled" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500" @disabled(! $canManage)> On
                        </label>
                    </x-slot:actions>
                    <div class="space-y-5 text-sm">
                        <div>
                            <label class="flex items-center gap-2 font-medium text-ink">
                                <input type="checkbox" wire:model="form.text_lead" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500" @disabled(! $canManage)> Text them
                            </label>
                            <textarea rows="3" wire:model="form.text_message" aria-label="Text message" class="sh-input mt-2" maxlength="320" @disabled(! $canManage) placeholder="{{ \App\Models\LeadSettings::DEFAULT_TEXT }}"></textarea>
                            <p class="mt-1 text-xs text-subtle">{first_name} and {business} are filled in. "Reply STOP to opt out" is added if it's missing. Texts wait until 8 AM at night.
                                @unless ($texting) <span class="text-amber-300">Texting isn't on for your SureHelp line yet, so texts aren't sent.</span>@endunless</p>
                            @error('form.text_message')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="flex items-center gap-2 font-medium text-ink">
                                <input type="checkbox" wire:model="form.email_lead" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500" @disabled(! $canManage)> Email them
                            </label>
                            <textarea rows="4" wire:model="form.email_message" aria-label="Email message" class="sh-input mt-2" maxlength="2000" @disabled(! $canManage) placeholder="{{ \App\Models\LeadSettings::DEFAULT_EMAIL }}"></textarea>
                            <p class="mt-1 text-xs text-subtle">Sent in your business's name; replies come to your business email.</p>
                        </div>
                        <div>
                            <label class="flex items-start gap-2 font-medium text-ink">
                                <input type="checkbox" wire:model="form.connect_call" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500" @disabled(! $canManage)>
                                <span>Ring me and connect me<span class="block text-xs font-normal text-muted">Your phone rings from your SureHelp number: you hear who it is, press 1, and we call them.
                                    {{ $ownerNumber ? 'Rings '.\App\Models\PhoneNumber::pretty($ownerNumber).'.' : 'Add a transfer number to your phone line first.' }}</span></span>
                            </label>
                            <label class="ml-6 mt-2 flex items-center gap-2 text-muted">
                                <input type="checkbox" wire:model="form.call_in_hours_only" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500" @disabled(! $canManage)> Only during my opening hours
                            </label>
                        </div>
                        @if ($canManage)<x-ui.button type="submit">Save</x-ui.button>@endif
                    </div>
                </x-ui.card>
            </form>

            @if ($link)
                <x-ui.card title="Your lead link" description="Send leads from Facebook lead ads, Google Local Services, Angi, Thumbtack, Yelp or Nextdoor here, with a connector like Zapier or Make.">
                    <div class="space-y-3 text-sm">
                        <div class="flex gap-2">
                            <input type="text" readonly value="{{ $link }}" aria-label="Your lead link" class="sh-input font-mono text-xs" x-on:focus="$el.select()">
                            <x-ui.button type="button" variant="secondary" size="sm" data-link="{{ $link }}" x-data x-on:click="navigator.clipboard?.writeText($el.dataset.link); $dispatch('toast', { type: 'success', message: 'Link copied.' })">Copy</x-ui.button>
                        </div>
                        <ol class="list-decimal space-y-1 pl-5 text-muted">
                            <li>In Zapier, make a Zap: the trigger is your lead source (for example "Facebook Lead Ads: New Lead").</li>
                            <li>Add the action "Webhooks by Zapier: POST" with this link as the URL.</li>
                            <li>Send the fields <code>name</code>, <code>phone</code>, <code>email</code>, <code>message</code> and <code>source</code> (facebook, google, angi, thumbtack, yelp or nextdoor).</li>
                        </ol>
                        <p class="text-xs text-subtle">Keep the link private: anyone with it can send you leads. If it was shared by mistake, make a new one.</p>
                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="newLink" wire:confirm="Make a new link? The old one stops working straight away.">Make a new link</x-ui.button>
                    </div>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
