<div>
    <x-ui.page-header title="Data & privacy" description="Download everything you have with us, choose how long we keep your history, or close your account." />

    @if ($organization->isClosing())
        <x-ui.alert tone="warning" class="mb-6" :title="'Your account closes on '.$organization->closes_at->setTimezone($timezone)->format('l, F j')">
            We keep answering your calls until then. On that day your customers, calls, appointments and settings are deleted for good and your team can no longer sign in.
            <span class="mt-3 block"><x-ui.button wire:click="keepAccount">Keep my account</x-ui.button></span>
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Download your data" description="A ZIP with your customers, calls, appointments, tasks, escalations and invoices as spreadsheets (CSV), plus your business settings.">
            <x-ui.button icon="download" wire:click="requestExport">Prepare an export</x-ui.button>
            <p class="mt-2 text-sm text-muted">It takes a minute or two. We'll email you when it's ready; the file is kept for 7 days.</p>

            @if ($exports->isNotEmpty())
                <ul class="mt-5 divide-y divide-line rounded-xl border border-line" role="list" wire:poll.10s>
                    @foreach ($exports as $export)
                        <li class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm" wire:key="export-{{ $export->id }}">
                            <span class="min-w-0 flex-1">
                                <span class="block text-ink">{{ $export->created_at->setTimezone($timezone)->format('M j, g:i A') }}</span>
                                <span class="block text-xs text-subtle">by {{ $export->requester->name ?? 'a former team member' }}@if ($export->size_bytes) · {{ \Illuminate\Support\Number::fileSize($export->size_bytes, 1) }}@endif</span>
                            </span>
                            @if ($export->isDownloadable())
                                <x-ui.button size="sm" variant="secondary" icon="download" :href="route('app.settings.privacy.export', $export)">Download</x-ui.button>
                            @elseif ($export->status === 'pending')
                                <x-ui.badge tone="progress">Preparing…</x-ui.badge>
                            @else
                                <x-ui.badge tone="danger">Didn't work. Try again</x-ui.badge>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card title="How long we keep your history" description="Calls, past appointments, finished tasks and escalations, customer timelines and archived customers older than this are deleted automatically. Open work is never deleted.">
            <form wire:submit="saveRetention" class="flex flex-wrap items-end gap-3">
                <div class="min-w-48 flex-1">
                    <label for="p-retention" class="sh-label">Keep history for</label>
                    <select id="p-retention" wire:model="retention" class="sh-input">
                        @foreach ($choices as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('retention') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <x-ui.button type="submit">Save</x-ui.button>
            </form>
            <p class="mt-3 text-sm text-muted">A shorter period means less personal data on file. Your monthly results for deleted months are no longer available.</p>
        </x-ui.card>
    </div>

    <x-ui.card class="mt-6" title="Erasing one customer" description="When a customer asks you to delete their information, open them under Customers and choose “Erase personal data”. Their name and contact details are removed from every call and appointment.">
        <x-ui.button variant="secondary" :href="route('app.customers.index')">Go to Customers</x-ui.button>
    </x-ui.card>

    @if ($isOwner && ! $organization->isClosing())
        <x-ui.card class="mt-6 border-red-500/30" title="Close your account" description="We stop answering your calls after 30 days, then delete your customers, calls, appointments and settings, and remove your team's sign-ins. You can change your mind until then. Invoices are kept, as the law requires.">
            <form wire:submit="closeAccount" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="p-name" class="sh-label">Type <span class="font-semibold text-ink">{{ $organization->name }}</span> to confirm</label>
                    <input id="p-name" type="text" wire:model="confirmName" class="sh-input" autocomplete="off">
                    @error('confirmName') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="p-password" class="sh-label">Your password</label>
                    <input id="p-password" type="password" wire:model="password" class="sh-input" autocomplete="current-password">
                    @error('password') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <x-ui.button type="submit" variant="danger">Close my account in 30 days</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif
</div>
