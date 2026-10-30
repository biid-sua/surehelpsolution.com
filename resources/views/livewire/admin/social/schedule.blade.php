<div>
    <x-ui.page-header title="SureHelp social media" description="Choose when to post. {{ $writer }} writes each post a day ahead, with a picture or video when the mix says so. You can edit or cancel any post under Posts before it goes out." />
    @include('livewire.client.social._tabs')

    @if ($accounts->isEmpty())
        <x-ui.alert tone="warning" class="mb-6">
            No accounts are connected yet. Connect them under <a href="{{ $this->socialRoute('accounts') }}" class="font-medium underline">Accounts</a> first.
        </x-ui.alert>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <form wire:submit="save" class="space-y-6 xl:col-span-2">
            <x-ui.card title="When to post">
                <x-slot:actions>
                    <label class="flex items-center gap-2 text-sm font-medium text-ink">
                        <input type="checkbox" wire:model="form.enabled" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500" @disabled(! $canManage)>
                        Post automatically
                    </label>
                </x-slot:actions>

                <div class="space-y-5">
                    <div>
                        <p class="sh-label">Times each day</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @forelse ($form['times'] as $time)
                                <span wire:key="time-{{ $time }}" class="inline-flex items-center gap-1 rounded-full bg-surface-2 px-3 py-1 text-sm text-ink ring-1 ring-line">
                                    {{ \Carbon\Carbon::createFromFormat('H:i', $time)->format('g:i A') }}
                                    @if ($canManage)
                                        <button type="button" wire:click="removeTime('{{ $time }}')" class="text-subtle hover:text-danger" aria-label="Remove {{ $time }}">&times;</button>
                                    @endif
                                </span>
                            @empty
                                <span class="text-sm text-muted">No times yet.</span>
                            @endforelse
                        </div>
                        @if ($canManage)
                            <div class="mt-3 flex max-w-xs gap-2">
                                <input type="time" wire:model="newTime" class="sh-input" aria-label="Add a time">
                                <x-ui.button type="button" variant="secondary" wire:click="addTime">Add</x-ui.button>
                            </div>
                        @endif
                        @error('newTime')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                        @error('form.times')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <p class="sh-label">Days</p>
                        <div class="mt-2 flex flex-wrap gap-3">
                            @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $day => $label)
                                <label class="flex items-center gap-1.5 text-sm text-ink">
                                    <input type="checkbox" value="{{ $day }}" wire:model="form.days" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="max-w-sm">
                        <label for="tz" class="sh-label">Time zone</label>
                        <select id="tz" wire:model="form.timezone" class="sh-input">
                            @foreach (timezone_identifiers_list(\DateTimeZone::AMERICA) as $tz)<option value="{{ $tz }}">{{ str_replace('_', ' ', $tz) }}</option>@endforeach
                            @foreach (['UTC', 'Europe/London', 'Asia/Dhaka'] as $tz)<option value="{{ $tz }}">{{ str_replace('_', ' ', $tz) }}</option>@endforeach
                        </select>
                        @error('form.timezone')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <p class="sh-label">Post to</p>
                        <p class="text-xs text-subtle">Tick none to post to every connected account.</p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($accounts as $account)
                                <label wire:key="acc-{{ $account->ulid }}" class="flex items-center gap-2 text-sm text-ink">
                                    <input type="checkbox" value="{{ $account->ulid }}" wire:model="form.accounts" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                                    <span class="truncate">{{ $account->network->label() }}: {{ $account->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <label class="flex items-start gap-2 text-sm text-ink">
                        <input type="checkbox" wire:model="form.approval" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                        <span>Approve each post before it goes out<span class="block text-xs text-muted">Off: posts go out at their time on their own. On: they wait under Posts until someone approves them.</span></span>
                    </label>
                </div>
            </x-ui.card>

            <x-ui.card title="What to post" description="Each time gets one kind of post, picked by these weights.">
                <div class="space-y-5">
                    <div class="grid gap-4 sm:grid-cols-3">
                        @foreach (\App\Services\Social\House\HouseSocial::KINDS as $kind => $label)
                            <div>
                                <label for="mix-{{ $kind }}" class="sh-label">{{ $label }}</label>
                                <input id="mix-{{ $kind }}" type="number" min="0" max="100" wire:model="form.mix.{{ $kind }}" class="sh-input">
                            </div>
                        @endforeach
                    </div>
                    @error('form.mix')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                    <p class="text-xs text-subtle">Video posts use videos from the media library, least recently used first. When there are none, a picture or text post is made instead. YouTube only takes video posts.</p>

                    <div class="max-w-xl">
                        <label for="pictures" class="sh-label">Pictures</label>
                        <select id="pictures" wire:model="form.pictures" class="sh-input">
                            @foreach (\App\Services\Social\House\HouseSocial::PICTURES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                        @unless ($picturesReady)
                            <p class="mt-1 text-xs text-warning">ChatGPT isn't connected, so only library pictures are used. Add its key under Admin › Connections.</p>
                        @endunless
                    </div>

                    <div>
                        <label for="about" class="sh-label">About SureHelp</label>
                        <textarea id="about" rows="3" wire:model="form.about" class="sh-input"></textarea>
                    </div>
                    <div>
                        <label for="topics" class="sh-label">Topics, one per line</label>
                        <textarea id="topics" rows="5" wire:model="form.topics" class="sh-input"></textarea>
                        <p class="mt-1 text-xs text-subtle">Used in turn, so posts don't repeat.</p>
                    </div>
                    <div>
                        <label for="voice" class="sh-label">How posts should sound</label>
                        <textarea id="voice" rows="2" wire:model="form.voice" class="sh-input"></textarea>
                    </div>
                    <div class="max-w-xl">
                        <label for="link" class="sh-label">Link to share</label>
                        <input id="link" type="url" wire:model="form.link" class="sh-input">
                    </div>
                </div>
            </x-ui.card>

            @if ($canManage)
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit">Save schedule</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="prepareNext" wire:loading.attr="disabled" wire:target="prepareNext">
                        <span wire:loading.remove wire:target="prepareNext">Prepare the next post now</span>
                        <span wire:loading wire:target="prepareNext">Writing…</span>
                    </x-ui.button>
                </div>
            @endif
        </form>

        <div class="space-y-6">
            <x-ui.card title="Coming up" :description="'Times in '.str_replace('_', ' ', $timezoneLabel).'.'" :padding="false">
                <ul class="divide-y divide-line">
                    @forelse ($upcoming as $row)
                        <li wire:key="slot-{{ $row['at']->timestamp }}" class="flex items-start justify-between gap-3 px-5 py-3 text-sm">
                            <span class="whitespace-nowrap text-muted">{{ $row['at']->format('D j M, g:i A') }}</span>
                            @if ($row['post'])
                                <a href="{{ $this->socialRoute('posts.edit', $row['post']) }}" class="min-w-0 text-right">
                                    <x-ui.badge :tone="$row['post']->status->tone()">{{ $row['post']->status->label() }}</x-ui.badge>
                                    <span class="mt-1 block truncate text-ink">{{ $row['post']->excerpt(50) }}</span>
                                </a>
                            @else
                                <span class="text-subtle">{{ $form['enabled'] ? 'Written a day ahead' : 'Off' }}</span>
                            @endif
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-muted">Add times and days to see the schedule.</li>
                    @endforelse
                </ul>
            </x-ui.card>

            <x-ui.card title="Last run">
                @if ($lastRun)
                    <p class="text-sm text-ink">{{ \Carbon\Carbon::parse($lastRun['at'])->diffForHumans() }}: {{ $lastRun['prepared'] }} {{ \Illuminate\Support\Str::plural('post', $lastRun['prepared']) }} prepared.</p>
                    @foreach ($lastRun['errors'] ?? [] as $error)
                        <p class="mt-2 text-sm text-danger">{{ $error }}</p>
                    @endforeach
                @else
                    <p class="text-sm text-muted">Not run yet. It checks every 15 minutes once posting is on.</p>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
