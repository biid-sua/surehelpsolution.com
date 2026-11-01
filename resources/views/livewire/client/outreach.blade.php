<div>
    <x-ui.page-header title="Reminders & win-back" description="Bring past customers back: a reminder some months after each job, and campaigns to customers you haven't seen in a while." />

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat label="Service reminders sent (90 days)" icon="bell" :value="number_format($reminderStats['sent'])" />
        <x-ui.stat label="Booked again within 30 days" icon="check-circle" :value="number_format($reminderStats['back'])" />
        <x-ui.stat label="Campaigns sent" icon="megaphone" :value="number_format($campaigns->count())" />
    </div>

    <div class="grid gap-6 lg:grid-cols-5">
        <div class="space-y-6 lg:col-span-3">
            <x-ui.card title="Service reminders" description="After a job is completed, we remind the customer when the next one is due, unless they've already booked again.">
                @if ($services->isEmpty())
                    <p class="text-sm text-muted">Add your services under Business › Services first.</p>
                @else
                    <form wire:submit="saveReminders" class="space-y-4 text-sm">
                        @foreach ($services as $service)
                            <div wire:key="svc-{{ $service->id }}" class="grid gap-2 sm:grid-cols-[1fr_9rem] sm:items-start">
                                <div>
                                    <p class="font-medium text-ink">{{ $service->name }}</p>
                                    <input type="text" wire:model="reminders.{{ $service->id }}.text" maxlength="300" aria-label="Reminder message for {{ $service->name }}" class="sh-input mt-1"
                                        placeholder="Hi {first_name}, it's been a while since your {service} with {business}. Time to book the next one?" @disabled(! $canManage)>
                                    @error('reminders.'.$service->id.'.text')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <select wire:model="reminders.{{ $service->id }}.months" aria-label="When to remind for {{ $service->name }}" class="sh-input" @disabled(! $canManage)>
                                        <option value="">No reminder</option>
                                        @foreach ([1, 2, 3, 6, 9, 12, 18, 24, 36] as $m)<option value="{{ $m }}">After {{ $m }} {{ \Illuminate\Support\Str::plural('month', $m) }}</option>@endforeach
                                    </select>
                                    @error('reminders.'.$service->id.'.months')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        @endforeach
                        <p class="text-xs text-subtle">{first_name}, {business}, {service} and {phone} are filled in. Texts go only to customers who agreed to texts; "Reply STOP to opt out" is added. Emails carry an unsubscribe link. Nothing goes out between 8 PM and 9 AM.</p>
                        @if ($canManage)<x-ui.button type="submit">Save reminders</x-ui.button>@endif
                    </form>
                @endif
            </x-ui.card>

            @if ($canManage)
                <x-ui.card title="New campaign" description="A one-off message to a group of past customers, for a slow month or a seasonal offer.">
                    <form wire:submit="launch" class="space-y-4 text-sm">
                        <div>
                            <label for="cp-name" class="sh-label">Name (only you see it)</label>
                            <input id="cp-name" type="text" wire:model="campaign.name" maxlength="120" class="sh-input" placeholder="Fall furnace check">
                            @error('campaign.name')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                        </div>
                        <fieldset class="grid gap-3 sm:grid-cols-3">
                            <legend class="sh-label sm:col-span-3">Who gets it</legend>
                            <select wire:model.live="campaign.inactive_months" aria-label="Haven't booked in" class="sh-input">
                                <option value="">All customers</option>
                                @foreach ([3, 6, 9, 12, 18, 24] as $m)<option value="{{ $m }}">Not back in {{ $m }} months</option>@endforeach
                            </select>
                            <select wire:model.live="campaign.service_id" aria-label="Had this service" class="sh-input">
                                <option value="">Any service</option>
                                @foreach ($services as $service)<option value="{{ $service->id }}">Had {{ $service->name }}</option>@endforeach
                            </select>
                            <select wire:model.live="campaign.tag_id" aria-label="Tagged" class="sh-input">
                                <option value="">Any tag</option>
                                @foreach ($tags as $tag)<option value="{{ $tag->id }}">Tagged {{ $tag->name }}</option>@endforeach
                            </select>
                        </fieldset>
                        <p class="rounded-lg bg-surface-2 px-3 py-2 text-muted">{{ number_format($groupSize) }} {{ \Illuminate\Support\Str::plural('customer', $groupSize) }} in this group: {{ number_format($textable) }} can get texts, {{ number_format($emailable) }} have an email address.</p>
                        <div class="flex gap-4">
                            <label class="flex items-center gap-2 text-ink"><input type="checkbox" value="sms" wire:model.live="campaign.channels" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Text</label>
                            <label class="flex items-center gap-2 text-ink"><input type="checkbox" value="email" wire:model.live="campaign.channels" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Email</label>
                        </div>
                        @error('campaign.channels')<p class="text-danger">{{ $message }}</p>@enderror
                        @if (in_array('sms', $campaign['channels'] ?? [], true))
                            <div>
                                <label for="cp-text" class="sh-label">Text</label>
                                <textarea id="cp-text" wire:model="campaign.text_body" rows="3" maxlength="300" class="sh-input"></textarea>
                                @error('campaign.text_body')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                            </div>
                        @endif
                        @if (in_array('email', $campaign['channels'] ?? [], true))
                            <div>
                                <label for="cp-subject" class="sh-label">Email subject</label>
                                <input id="cp-subject" type="text" wire:model="campaign.email_subject" maxlength="150" class="sh-input">
                                @error('campaign.email_subject')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                                <label for="cp-body" class="sh-label mt-3">Email</label>
                                <textarea id="cp-body" wire:model="campaign.email_body" rows="6" maxlength="5000" class="sh-input"></textarea>
                                @error('campaign.email_body')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                            </div>
                        @endif
                        <x-ui.button type="submit" icon="megaphone" wire:confirm="Send this campaign now? It goes to everyone in the group who can be reached, between 9 AM and 8 PM.">Send campaign</x-ui.button>
                    </form>
                </x-ui.card>
            @endif
        </div>

        <div class="lg:col-span-2">
            <x-ui.card title="Campaigns" :padding="false">
                <ul class="divide-y divide-line text-sm" role="list">
                    @forelse ($campaigns as $row)
                        @php
                            $c = $row['campaign'];
                        @endphp
                        <li class="px-5 py-3" wire:key="cp-{{ $c->ulid }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="font-medium text-ink">{{ $c->name }}</span>
                                <x-ui.badge :tone="$c->status === 'sent' ? 'success' : 'info'">{{ $c->status === 'sent' ? 'Sent' : 'Sending' }}</x-ui.badge>
                            </div>
                            <p class="mt-1 text-xs text-muted">{{ $c->created_at->timezone($timezone)->format('M j, Y') }} · reached {{ number_format($c->sent_count) }} of {{ number_format($c->recipients_count) }} · <span class="text-ink">{{ number_format($row['booked']) }} booked</span></p>
                        </li>
                    @empty
                        <li class="px-5 py-6 text-subtle">No campaigns yet.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>
    </div>
</div>
