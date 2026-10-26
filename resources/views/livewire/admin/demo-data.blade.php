<div @if ($busy) wire:poll.3s @endif>
    <x-ui.page-header title="Demo data" description="Sample businesses with four months of history, to learn the system before real customers arrive. Only administrators can load or remove it." />

    @if (! empty($state['error']))
        <x-ui.alert tone="danger" class="mb-6" title="{{ $state['status'] === 'failed_removal' ? 'The demo data couldn\'t be removed' : 'The last attempt didn\'t finish' }}">
            {{ $state['error'] }}
            @if ($state['status'] === 'failed')
                Nothing was loaded: it's all or nothing. You can try again.
            @elseif ($state['status'] === 'failed_removal')
                Nothing was removed. You can try again; if it keeps failing, run <code>php artisan demo:data remove</code> on the server.
            @endif
        </x-ui.alert>
    @endif

    @if ($state['stuck'])
        <x-ui.alert tone="warning" class="mb-6" title="This seems to have stopped">
            The {{ $state['status'] === 'loading' ? 'load' : 'removal' }} started {{ \Illuminate\Support\Carbon::parse($state['started_at'])->diffForHumans() }} and hasn't finished. The server may have stopped it.
            @if ($canManage)
                <div class="mt-3"><x-ui.button size="sm" variant="secondary" wire:click="recover">Check what's in the system</x-ui.button></div>
            @endif
        </x-ui.alert>
    @endif

    @if ($busy)
        <x-ui.card>
            <div class="flex items-center gap-4" role="status">
                <svg class="h-6 w-6 animate-spin text-brand-400" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                <div>
                    <p class="font-medium text-ink">{{ $state['status'] === 'loading' ? 'Loading the demo data…' : 'Removing the demo data…' }}</p>
                    <p class="text-sm text-muted">Started by {{ $state['started_by'] ?? 'an administrator' }} {{ isset($state['started_at']) ? \Illuminate\Support\Carbon::parse($state['started_at'])->diffForHumans() : '' }}. It usually takes one to three minutes. You can leave this page.</p>
                </div>
            </div>
        </x-ui.card>
    @elseif (in_array($state['status'], ['loaded', 'failed_removal'], true))
        <div class="grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <x-ui.card title="Demo businesses" description="Loaded {{ isset($state['loaded_at']) ? \Illuminate\Support\Carbon::parse($state['loaded_at'])->diffForHumans() : '' }}{{ isset($state['loaded_by']) ? ' by '.$state['loaded_by'] : '' }}. Open one, or view the portal as its owner.">
                <ul class="divide-y divide-line">
                    @foreach ($businesses as $business)
                        <li wire:key="b-{{ $business->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <a href="{{ route('admin.organizations.show', $business) }}" class="font-medium text-ink hover:text-brand-300">{{ $business->name }}</a>
                                <p class="text-xs text-subtle">{{ $business->status->label() }} · owner {{ $business->owner?->name }}</p>
                            </div>
                            @if ($canImpersonate && $business->owner?->is_active)
                                <form method="POST" action="{{ route('admin.impersonate', $business->owner) }}">
                                    @csrf
                                    <x-ui.button type="submit" size="sm" variant="secondary" icon="user">View as {{ \Illuminate\Support\Str::before(preg_replace('/^(Dr|Mr|Mrs|Ms)\.\s+/', '', $business->owner->name).' ', ' ') }}</x-ui.button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <p class="mt-4 text-sm text-muted">The demo agents (John Carter and Mike Johnson) appear under Agent assignments, Agent University and the duty schedule. Demo accounts have passwords nobody knows, so nobody can sign in to them.</p>
            </x-ui.card>

            <x-ui.card title="Remove the demo data" description="Deletes the demo businesses, people and everything in them, including anything added inside them since.">
                <ul class="mb-4 list-disc space-y-1 pl-5 text-sm text-muted">
                    <li>Real businesses, people and settings are never deleted.</li>
                    <li>If real data uses a demo item (for example a real business on a demo plan), that item is kept and listed afterwards.</li>
                </ul>
                @if ($canManage)
                    <form wire:submit="remove" class="space-y-3">
                        <div>
                            <label for="demo-confirm" class="sh-label">Type REMOVE to confirm</label>
                            <input id="demo-confirm" type="text" wire:model="confirm" class="sh-input w-40" autocomplete="off">
                            @error('confirm')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                        </div>
                        <x-ui.button type="submit" variant="danger">Remove demo data</x-ui.button>
                    </form>
                @else
                    <p class="text-sm text-muted">Only administrators with settings access can remove it.</p>
                @endif
            </x-ui.card>
        </div>
    @else
        <div class="grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <x-ui.card title="What you get" description="A realistic answering service, four months in.">
                <ul class="space-y-2 text-sm text-ink">
                    <li><span class="font-medium">Five businesses:</span> Rivera Plumbing &amp; Heating (busy, on the top plan), Bright Smile Dental, Lakeside Law Group, Green Valley HVAC (still setting up) and Sunrise Yoga Studio (paused).</li>
                    <li><span class="font-medium">Their history:</span> calls, escalations, appointments, customers, inbox conversations, tasks, invoices and payments, support requests, call quality reviews, social posts, Google reviews, website and SEO results, phone lines and automations.</li>
                    <li><span class="font-medium">Your team side:</span> two demo agents with shifts, training progress and certificates, plus website enquiries.</li>
                </ul>
            </x-ui.card>

            <x-ui.card title="Safe to load" description="It stays inside SureHelp.">
                <ul class="mb-5 list-disc space-y-1 pl-5 text-sm text-muted">
                    <li>No emails or texts go to the demo's people, and background work (Google, social networks, payments, webhooks, AI) skips demo businesses.</li>
                    <li>Real data isn't changed. Demo plans are hidden from sign-up, and demo agents are never given real businesses.</li>
                    <li>Remove it here when you're done; only administrators can.</li>
                </ul>
                @if (! empty($state['last_removal']))
                    <p class="mb-4 text-sm text-muted">Last removed {{ \Illuminate\Support\Carbon::parse($state['removed_at'])->diffForHumans() }} by {{ $state['removed_by'] }}: {{ number_format($state['last_removal']['rows']) }} rows.
                        @if (($state['last_removal']['kept'] ?? 0) > 0)
                            {{ $state['last_removal']['kept'] }} demo {{ str('item')->plural($state['last_removal']['kept']) }} kept because real data uses them ({{ collect($state['last_removal']['kept_tables'])->map(fn ($n, $t) => str_replace('_', ' ', $t).' '.$n)->implode(', ') }}).
                        @endif
                    </p>
                @endif
                @if ($canManage)
                    <x-ui.button wire:click="load" wire:confirm="Load the demo businesses and their history? You can remove them again here.">Load demo data</x-ui.button>
                @else
                    <p class="text-sm text-muted">Only administrators with settings access can load it.</p>
                @endif
            </x-ui.card>
        </div>
    @endif
</div>
