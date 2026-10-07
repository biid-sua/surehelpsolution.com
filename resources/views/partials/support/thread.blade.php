{{-- One support request's messages. Needs $current (with messages.author) and $downloadRoute (route name taking the message id). --}}
<ol class="space-y-4" role="list">
    @foreach ($current->messages as $m)
        <li wire:key="sm-{{ $m->id }}" @class(['rounded-xl border p-4', 'border-brand-500/30 bg-brand-500/5' => $m->is_staff, 'border-line bg-surface-2/40' => ! $m->is_staff])>
            <p class="flex flex-wrap items-center gap-2 text-xs text-subtle">
                <span class="font-medium text-ink">{{ $m->is_staff ? 'SureHelp'.($m->author ? ' · '.$m->author->name : '') : ($m->author?->name ?? 'Former team member') }}</span>
                <span>{{ $m->created_at->diffForHumans() }}</span>
            </p>
            <p class="mt-2 whitespace-pre-line text-sm text-ink">{{ $m->body }}</p>
            @if ($m->attachment_path)
                <a href="{{ route($downloadRoute, $m->id) }}" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-surface px-3 py-1.5 text-xs text-muted ring-1 ring-line hover:text-ink">
                    <x-ui.icon name="download" class="size-4" /> {{ $m->attachment_name }} <span class="text-subtle">({{ \Illuminate\Support\Number::fileSize((int) $m->attachment_size) }})</span>
                </a>
            @endif
        </li>
    @endforeach
</ol>
