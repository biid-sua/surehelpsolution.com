<div>
    <x-ui.page-header title="System status" description="Is everything ready to go live? Each item says what's missing and how to fix it. The same checks run with php artisan launch:check." />

    @php
        $tones = ['ok' => 'success', 'warn' => 'warning', 'fail' => 'danger', 'off' => 'neutral'];
        $labels = ['ok' => 'Ready', 'warn' => 'Recommended', 'fail' => 'Fix before launch', 'off' => 'Off'];
    @endphp

    <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-ui.stat label="Ready" icon="check-circle" :value="$summary['ok']" />
        <x-ui.stat label="Fix before launch" icon="alert" :value="$summary['fail']" />
        <x-ui.stat label="Recommended" icon="info" :value="$summary['warn']" />
        <x-ui.stat label="Features off" icon="settings" :value="$summary['off']" />
    </div>

    @if ($summary['fail'] === 0)
        <x-ui.alert tone="success" class="mb-6" title="Ready to go live">Features marked "Off" stay hidden from clients until their settings are filled in.</x-ui.alert>
    @else
        <x-ui.alert tone="danger" class="mb-6" title="{{ $summary['fail'] }} {{ \Illuminate\Support\Str::plural('item', $summary['fail']) }} to fix before launch">Start with the red items below.</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="space-y-6">
            @foreach ($groups as $group => $rows)
                <x-ui.card :title="$group" :padding="false">
                    <ul class="divide-y divide-line">
                        @foreach ($rows as $row)
                            <li class="flex items-start gap-3 px-5 py-3" wire:key="check-{{ $loop->parent->index }}-{{ $loop->index }}">
                                <x-ui.badge :tone="$tones[$row['status']]" class="mt-0.5 shrink-0">{{ $labels[$row['status']] }}</x-ui.badge>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-ink">{{ $row['label'] }}</p>
                                    <p class="truncate text-xs text-subtle">{{ $row['detail'] }}</p>
                                    @if ($row['fix'] !== '')<p class="mt-1 text-sm text-muted">{{ $row['fix'] }}</p>@endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endforeach
        </div>

        <div class="space-y-6">
            @if ($canManage)
                <x-ui.card title="Send a test email" description="Checks that emails leave the server with your sender address.">
                    <form wire:submit="sendTestEmail" class="flex gap-2">
                        <label for="test-email" class="sr-only">Email address</label>
                        <input id="test-email" type="email" wire:model="testEmail" class="sh-input flex-1" autocomplete="email">
                        <x-ui.button type="submit" size="sm">Send</x-ui.button>
                    </form>
                    @error('testEmail')<p class="mt-1 text-xs text-red-300">{{ $message }}</p>@enderror
                </x-ui.card>
            @endif

            <x-ui.card title="Addresses to register" description="Copy these into each provider's settings exactly as shown.">
                <div class="space-y-5">
                    @foreach ($urls as $where => $list)
                        <div>
                            <h3 class="text-sm font-semibold text-ink">{{ $where }}</h3>
                            <ul class="mt-2 space-y-2">
                                @foreach ($list as $u)
                                    <li>
                                        <p class="text-xs text-subtle">{{ $u['label'] }}</p>
                                        <div class="flex items-center gap-2" x-data="{ copied: false }">
                                            <code class="min-w-0 flex-1 truncate rounded-md bg-surface-2 px-2 py-1 text-xs text-ink ring-1 ring-line" title="{{ $u['url'] }}">{{ $u['url'] }}</code>
                                            <button type="button" class="shrink-0 text-xs text-brand-300 hover:underline"
                                                x-on:click="navigator.clipboard.writeText(@js($u['url'])); copied = true; setTimeout(() => copied = false, 1500)"
                                                x-text="copied ? 'Copied' : 'Copy'" aria-label="Copy {{ $u['label'] }}">Copy</button>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
