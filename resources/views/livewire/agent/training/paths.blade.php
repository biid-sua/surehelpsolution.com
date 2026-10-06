<div>
    <x-ui.page-header title="Training management" description="Learning paths put courses in a recommended order.">
        <x-slot:actions>
            @if ($editing === null)<x-ui.button wire:click="create" icon="list">New learning path</x-ui.button>@endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-university.manage-nav active="paths" />

    @if ($editing !== null)
        <x-ui.card :title="$editing ? 'Edit learning path' : 'New learning path'" class="mb-6">
            <form wire:submit="save" class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="lp-title" class="sh-label">Title</label>
                    <input id="lp-title" type="text" wire:model="form.title" class="sh-input" maxlength="200" placeholder="e.g. Customer service essentials">
                    @error('form.title')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="lp-for" class="sh-label">For</label>
                    <select id="lp-for" wire:model.live="form.for" class="sh-input" @disabled($editing)>
                        @foreach ($audiences as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    @error('form.for')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <div class="md:col-span-2">
                    <label for="lp-desc" class="sh-label">Description</label>
                    <textarea id="lp-desc" wire:model="form.description" rows="2" class="sh-input"></textarea>
                </div>
                <div class="md:col-span-2">
                    <p class="sh-label">Courses, in order</p>
                    <ol class="space-y-2">
                        @foreach ($form['courses'] as $i => $courseId)
                            <li class="flex items-center gap-2 rounded-lg border border-line px-3 py-2" wire:key="lpc-{{ $courseId }}">
                                <span class="text-sm font-semibold text-subtle">{{ $i + 1 }}.</span>
                                <span class="flex-1 text-sm text-ink">{{ $eligible[$courseId] ?? 'Course no longer available' }}</span>
                                <button type="button" wire:click="move({{ $i }}, -1)" class="rounded p-1 text-muted hover:text-ink disabled:opacity-30" @disabled($i === 0) aria-label="Move up"><x-ui.icon name="chevron-left" class="size-4 rotate-90" /></button>
                                <button type="button" wire:click="move({{ $i }}, 1)" class="rounded p-1 text-muted hover:text-ink disabled:opacity-30" @disabled($loop->last) aria-label="Move down"><x-ui.icon name="chevron-right" class="size-4 rotate-90" /></button>
                                <button type="button" wire:click="remove({{ $i }})" class="rounded p-1 text-muted hover:text-danger" aria-label="Remove"><x-ui.icon name="x" class="size-4" /></button>
                            </li>
                        @endforeach
                    </ol>
                    <div class="mt-2 flex gap-2">
                        <label for="lp-add" class="sr-only">Add a course</label>
                        <select id="lp-add" wire:model="addCourse" class="sh-input">
                            <option value="">Add a course…</option>
                            @foreach ($eligible as $id => $title)
                                @continue(in_array($id, $form['courses'], true))
                                <option value="{{ $id }}">{{ $title }}</option>
                            @endforeach
                        </select>
                        <x-ui.button variant="secondary" wire:click="add">Add</x-ui.button>
                    </div>
                    @error('form.courses')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <label class="flex items-center gap-2 text-sm text-ink md:col-span-2"><input type="checkbox" wire:model="form.is_active"> Shown to agents</label>
                <div class="flex gap-3 md:col-span-2">
                    <x-ui.button type="submit">Save path</x-ui.button>
                    <x-ui.button variant="ghost" wire:click="cancel">Cancel</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card :padding="false">
        <ul class="divide-y divide-line">
            @forelse ($paths as $path)
                <li class="flex flex-wrap items-start gap-3 px-5 py-4" wire:key="lp-{{ $path->id }}">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-ink">{{ $path->title }} @unless ($path->is_active)<x-ui.badge>Hidden</x-ui.badge>@endunless</p>
                        <p class="text-xs text-subtle">{{ $path->organization?->name ?? 'All agents' }} · {{ $path->courses->count() }} {{ \Illuminate\Support\Str::plural('course', $path->courses->count()) }}</p>
                        <p class="mt-1 text-sm text-muted">{{ $path->courses->pluck('title')->implode(' → ') }}</p>
                    </div>
                    <x-ui.button size="sm" variant="ghost" wire:click="edit({{ $path->id }})">Edit</x-ui.button>
                </li>
            @empty
                <li><x-ui.empty-state icon="list" title="No learning paths yet" description="Group courses into a path, like Customer service → Call handling → Escalations, so agents know what to take next." /></li>
            @endforelse
        </ul>
    </x-ui.card>
</div>
