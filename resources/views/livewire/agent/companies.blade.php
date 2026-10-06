<div>
    <x-ui.page-header title="My companies" :description="$isAdmin ? 'As platform staff you can open every active company.' : 'The companies you are assigned to. Only these appear anywhere in your portal.'" />

    @if ($companies->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="building" title="No companies assigned yet" description="A supervisor assigns you to the companies you'll serve. You'll get a notification when it happens." />
        </x-ui.card>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($companies as $c)
                @php($o = $c['organization'])
                <a href="{{ route('agent.businesses.show', $o->ulid) }}" wire:key="co-{{ $o->ulid }}" class="block rounded-2xl border border-line bg-surface p-5 shadow-[var(--shadow-card)] transition hover:border-brand-400/60">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-base font-semibold text-ink">{{ $o->name }}</p>
                            <p class="mt-0.5 text-xs text-subtle">{{ $c['local_time'] }} their time · {{ $c['status']['label'] }}</p>
                        </div>
                        @if ($c['assignment'])
                            <x-ui.badge :tone="\App\Models\AgentAssignment::tone($c['assignment']->effectiveStatus())">{{ \App\Models\AgentAssignment::label($c['assignment']->effectiveStatus()) }}</x-ui.badge>
                        @endif
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-xs text-subtle">Appointments today</dt><dd class="text-lg font-semibold text-ink">{{ $c['appointments_today'] }}</dd></div>
                        <div><dt class="text-xs text-subtle">Open tasks</dt><dd class="text-lg font-semibold text-ink">{{ $c['open_tasks'] }}</dd></div>
                    </dl>
                    @if ($c['readiness'] && ! $c['readiness']['ready'])
                        <p class="mt-3 rounded-lg bg-amber-500/10 px-3 py-2 text-xs text-amber-200 ring-1 ring-amber-400/30">Training required: {{ count($c['readiness']['missing']) }} {{ \Illuminate\Support\Str::plural('course', count($c['readiness']['missing'])) }} to finish</p>
                    @endif
                    @if ($c['assignment']?->ends_at)
                        <p class="mt-3 text-xs text-subtle">Assigned until {{ $c['assignment']->ends_at->setTimezone($o->timezoneOrDefault())->format('j M Y') }}</p>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
</div>
