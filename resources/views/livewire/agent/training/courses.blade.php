<div>
    <x-ui.page-header title="Training management" description="Create courses, give them to agents, and follow their progress.">
        @if ($canCreate)
            <x-slot:actions><x-ui.button wire:click="startCreate" icon="sparkles">New course</x-ui.button></x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-university.manage-nav active="courses" />

    @if ($creating)
        <x-ui.card title="New course" description="Start with the basics. You'll add modules, lessons and quizzes next, then publish." class="mb-6">
            <form wire:submit="create" class="grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label for="c-title" class="sh-label">Title</label>
                    <input id="c-title" type="text" wire:model="form.title" class="sh-input" placeholder="e.g. Virtual Receptionist Fundamentals" maxlength="200" required>
                    @error('form.title')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="c-for" class="sh-label">For</label>
                    <select id="c-for" wire:model="form.for" class="sh-input">
                        @foreach ($choices as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    <p class="mt-1 text-xs text-subtle">Company courses are only ever visible to agents serving that company. This can't be changed later.</p>
                    @error('form.for')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="c-cat" class="sh-label">Category</label>
                    <select id="c-cat" wire:model="form.category" class="sh-input">
                        <option value="">None</option>
                        @foreach ($categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
                    </select>
                    <input type="text" wire:model="form.new_category" class="sh-input mt-2" placeholder="…or a new category" maxlength="100" aria-label="New category">
                </div>
                <div class="md:col-span-2">
                    <label for="c-sum" class="sh-label">Summary <span class="text-subtle">(optional)</span></label>
                    <input id="c-sum" type="text" wire:model="form.summary" class="sh-input" maxlength="500" placeholder="One sentence: what agents will be able to do afterwards">
                </div>
                <div class="flex gap-3 md:col-span-2">
                    <x-ui.button type="submit">Create and add content</x-ui.button>
                    <x-ui.button variant="ghost" wire:click="$set('creating', false)">Cancel</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    <div class="mb-4 flex flex-wrap gap-3">
        <div class="w-full max-w-sm">
            <label for="t-search" class="sr-only">Search courses</label>
            <input id="t-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input" placeholder="Search courses…">
        </div>
        <div>
            <label for="t-for" class="sr-only">Audience</label>
            <select id="t-for" wire:model.live="for" class="sh-input">
                <option value="">All courses</option>
                @if ($canPlatform)<option value="platform">Platform-wide</option>@endif
                @foreach ($companies as $c)<option value="{{ $c->ulid }}">{{ $c->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="t-state" class="sr-only">State</label>
            <select id="t-state" wire:model.live="state" class="sh-input">
                <option value="">Any state</option>
                <option value="published">Published</option>
                <option value="draft">Never published</option>
                <option value="inactive">Switched off</option>
            </select>
        </div>
    </div>

    <x-ui.card :padding="false">
        <x-ui.table>
            <thead><tr><th scope="col">Course</th><th scope="col">For</th><th scope="col">Version</th><th scope="col" class="text-right">Agents</th><th scope="col" class="text-right">Completed</th><th scope="col" class="text-right">Overdue</th></tr></thead>
            <tbody class="divide-y divide-line">
                @forelse ($courses as $course)
                    @php($n = $counts->get($course->id))
                    <tr wire:key="c-{{ $course->id }}">
                        <td>
                            <a href="{{ route('agent.training.course', $course->ulid) }}" class="font-medium text-ink hover:text-brand-300">{{ $course->title }}</a>
                            <p class="text-xs text-subtle">{{ $course->category?->name ?? 'No category' }}@unless ($course->is_active) · <span class="text-danger">switched off</span>@endunless</p>
                        </td>
                        <td class="text-sm text-muted">{{ $course->organization?->name ?? 'All agents' }}</td>
                        <td>
                            @if ($course->isPublished())
                                <span class="text-sm text-muted">v{{ $course->current_version }}</span>
                                @if ($course->hasUnpublishedChanges())<x-ui.badge tone="warning">Unpublished changes</x-ui.badge>@endif
                            @else
                                <x-ui.badge>Draft</x-ui.badge>
                            @endif
                        </td>
                        <td class="text-right text-muted">{{ (int) ($n->total ?? 0) }}</td>
                        <td class="text-right text-muted">{{ (int) ($n->done ?? 0) }}</td>
                        <td class="text-right {{ ($n->overdue ?? 0) > 0 ? 'text-danger' : 'text-muted' }}">{{ (int) ($n->overdue ?? 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-ui.empty-state icon="sparkles" title="No courses yet" description="Create a course, add lessons and a quiz, publish it, then give it to agents." /></td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
        <x-ui.pagination :paginator="$courses" />
    </x-ui.card>
</div>
