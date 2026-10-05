<div>
    <x-ui.page-header :title="$post ? 'Social post' : 'New social post'" description="Write once, post everywhere. Each network gets checked before anything is scheduled.">
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="arrow-left" :href="route('app.social.index')">All posts</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('success'))
        <x-ui.alert tone="success" class="mb-6">{{ session('success') }}</x-ui.alert>
    @endif

    {{-- Where the post stands --}}
    @if ($post)
        <x-ui.card class="mb-6">
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.badge :tone="$post->status->tone()">{{ $post->status->label() }}</x-ui.badge>
                <span class="text-sm text-muted">
                    @if ($post->scheduled_at && in_array($post->status->value, ['scheduled', 'in_review', 'draft'], true))
                        Planned for {{ $post->scheduled_at->setTimezone($timezone)->format('l j F Y, g:i A') }} ({{ $timezone }})
                    @elseif ($post->published_at)
                        Published {{ $post->published_at->setTimezone($timezone)->format('j M Y, g:i A') }}
                    @endif
                    · by {{ $post->source === 'team' ? 'the SureHelp team' : ($post->author->name ?? 'a former team member') }}
                    @if ($post->approver) · approved by {{ $post->approver->name }} @endif
                </span>
            </div>

            @if ($post->review_note && $post->status->value === 'draft')
                <x-ui.alert tone="warning" class="mt-4" title="Changes requested">{{ $post->review_note }}</x-ui.alert>
            @endif

            @if ($canApprove)
                <div class="mt-4 rounded-xl bg-surface-2 p-4 ring-1 ring-line">
                    <p class="font-medium text-ink">This post is waiting for your approval.</p>
                    @if ($post->scheduled_at && $post->scheduled_at->isPast())
                        <p class="mt-1 text-sm text-amber-300">Its planned time has passed: approving publishes it now.</p>
                    @endif
                    <div class="mt-3 flex flex-wrap items-start gap-3">
                        <x-ui.button wire:click="approve" icon="check-circle">Approve</x-ui.button>
                        <form wire:submit="requestChanges" class="flex min-w-64 flex-1 gap-2">
                            <label for="changes-note" class="sr-only">What should change?</label>
                            <input id="changes-note" type="text" wire:model="changesNote" class="sh-input" placeholder="What should change?" maxlength="1000">
                            <x-ui.button type="submit" variant="secondary">Request changes</x-ui.button>
                        </form>
                    </div>
                    @error('changesNote') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
            @endif

            @if (! $post->status->isEditable() && $post->status->value !== 'cancelled')
                <ul class="mt-4 divide-y divide-line rounded-xl ring-1 ring-line">
                    @foreach ($post->targets as $target)
                        <li class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm" wire:key="res-{{ $target->id }}">
                            <span class="font-medium text-ink">{{ $target->account?->displayName() ?? 'Removed account' }}</span>
                            <span class="text-subtle">{{ $target->account?->network->label() }}</span>
                            <span class="ml-auto flex items-center gap-2">
                                @switch($target->status)
                                    @case('published')
                                        <x-ui.badge tone="success">Published</x-ui.badge>
                                        @if ($target->external_url)<a href="{{ $target->external_url }}" target="_blank" rel="noopener" class="text-brand-300 underline">View</a>@endif
                                        @break
                                    @case('failed') <x-ui.badge tone="danger">Failed</x-ui.badge> @break
                                    @case('cancelled') <x-ui.badge>Cancelled</x-ui.badge> @break
                                    @default <x-ui.badge tone="info">{{ $target->attempts > 0 ? 'Retrying' : 'Publishing' }}</x-ui.badge>
                                @endswitch
                            </span>
                            @if ($target->last_error && $target->status !== 'published')
                                <p class="w-full text-xs text-danger">{{ $target->last_error }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($canManage)
                <div class="mt-4 flex flex-wrap gap-2">
                    @if (in_array($post->status->value, ['failed', 'partly_published'], true))
                        <x-ui.button size="sm" wire:click="retry" icon="refresh">Retry failed accounts</x-ui.button>
                    @endif
                    @if (in_array($post->status->value, ['draft', 'in_review', 'scheduled'], true))
                        <x-ui.button size="sm" variant="ghost" wire:click="cancel" wire:confirm="Cancel this post? Nothing will be published.">Cancel post</x-ui.button>
                    @endif
                    @if (in_array($post->status->value, ['draft', 'cancelled', 'failed'], true))
                        <x-ui.button size="sm" variant="ghost" wire:click="deletePost" wire:confirm="Delete this post for good?">Delete</x-ui.button>
                    @endif
                </div>
            @endif
        </x-ui.card>
    @endif

    @if ($accounts->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="megaphone" title="Connect an account first" description="Connect Facebook, Instagram, LinkedIn or Google Business Profile, then come back to write your post." />
            <div class="mt-4 text-center"><x-ui.button :href="route('app.social.accounts')">Connect accounts</x-ui.button></div>
        </x-ui.card>
    @else
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <div class="space-y-6">
                <x-ui.card>
                    <fieldset @disabled(! $editable)>
                        <legend class="sh-label">Post to</legend>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($accounts as $account)
                                <label wire:key="pick-{{ $account->ulid }}" @class([
                                    'flex cursor-pointer items-center gap-2 rounded-full px-3 py-1.5 text-sm ring-1 transition',
                                    'bg-surface-2 text-ink ring-brand-400' => in_array($account->ulid, $selected, true),
                                    'text-muted ring-line hover:ring-line-strong' => ! in_array($account->ulid, $selected, true),
                                    'opacity-50' => $account->needsReconnect(),
                                ])>
                                    <input type="checkbox" value="{{ $account->ulid }}" wire:model.live="selected" class="sr-only">
                                    <span class="size-2.5 rounded-full" style="background: {{ $account->network->color() }}"></span>
                                    {{ $account->displayName() }}
                                    <span class="text-xs text-subtle">{{ $account->network->shortLabel() }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('accounts') <p class="mt-2 text-sm text-danger">{{ $message }}</p> @enderror
                    </fieldset>

                    <div class="mt-5">
                        <label for="post-body" class="sh-label">Text</label>
                        <textarea id="post-body" wire:model.live.debounce.400ms="body" rows="7" class="sh-input" placeholder="What do you want to tell your customers?" @disabled(! $editable)></textarea>
                        <p class="mt-1 text-right text-xs text-subtle">{{ mb_strlen($body) }} characters</p>
                        @error('body') <div class="mt-1 space-y-0.5 text-sm text-danger">@foreach ($errors->get('body') as $m) <p>{{ $m }}</p> @endforeach</div> @enderror
                    </div>

                    <div class="mt-4">
                        <label for="post-link" class="sh-label">Link <span class="font-normal text-subtle">(optional)</span></label>
                        <input id="post-link" type="url" wire:model.live.debounce.500ms="link" class="sh-input" placeholder="https://" @disabled(! $editable)>
                        @error('link') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </x-ui.card>

                {{-- Photos --}}
                <x-ui.card>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="sh-label mb-0">Photos <span class="font-normal text-subtle">({{ count($media) }}/10)</span></h2>
                        @if ($editable)
                            <div class="flex gap-2">
                                <x-ui.button size="sm" variant="secondary" wire:click="$toggle('pickingMedia')">{{ $pickingMedia ? 'Close library' : 'From library' }}</x-ui.button>
                                <label class="inline-flex cursor-pointer items-center rounded-lg border border-line-strong bg-surface-2 px-3 py-1.5 text-sm font-medium text-ink hover:bg-surface-3">
                                    Upload
                                    <input type="file" wire:model="uploads" multiple accept="image/jpeg,image/png,image/webp" class="sr-only">
                                </label>
                            </div>
                        @endif
                    </div>
                    <div wire:loading wire:target="uploads" class="mt-2 text-sm text-muted">Uploading…</div>
                    @error('uploads') <p class="mt-2 text-sm text-danger">{{ $message }}</p> @enderror
                    @error('uploads.*') <p class="mt-2 text-sm text-danger">{{ $message }}</p> @enderror
                    @error('media') <p class="mt-2 text-sm text-danger">{{ $message }}</p> @enderror

                    @if ($media !== [])
                        <div class="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-5">
                            @foreach ($media as $i => $ulid)
                                @if ($asset = $mediaAssets->get($ulid))
                                    <div class="relative" wire:key="sel-{{ $ulid }}">
                                        <img src="{{ $asset->url() }}" alt="{{ $asset->alt_text }}" class="aspect-square w-full rounded-lg object-cover ring-1 ring-line">
                                        @if ($editable)
                                            <div class="absolute inset-x-1 bottom-1 flex justify-between gap-1">
                                                <button type="button" wire:click="moveMedia('{{ $ulid }}', -1)" class="rounded bg-black/70 px-1.5 text-xs text-white" aria-label="Move earlier" @disabled($i === 0)>‹</button>
                                                <button type="button" wire:click="removeMedia('{{ $ulid }}')" class="rounded bg-black/70 px-1.5 text-xs text-white" aria-label="Remove photo">✕</button>
                                                <button type="button" wire:click="moveMedia('{{ $ulid }}', 1)" class="rounded bg-black/70 px-1.5 text-xs text-white" aria-label="Move later" @disabled($i === count($media) - 1)>›</button>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    @if ($pickingMedia)
                        <div class="mt-4 grid max-h-80 grid-cols-4 gap-2 overflow-y-auto rounded-lg bg-surface-2 p-2 sm:grid-cols-6">
                            @forelse ($library as $asset)
                                <button type="button" wire:key="lib-{{ $asset->ulid }}" wire:click="addMedia('{{ $asset->ulid }}')" @class(['rounded-md ring-2', 'ring-brand-400' => in_array($asset->ulid, $media, true), 'ring-transparent' => ! in_array($asset->ulid, $media, true)])>
                                    <img src="{{ $asset->url() }}" alt="{{ $asset->alt_text ?: 'Add photo' }}" class="aspect-square w-full rounded-md object-cover" loading="lazy">
                                </button>
                            @empty
                                <p class="col-span-full p-4 text-sm text-muted">Your library is empty. Upload photos above.</p>
                            @endforelse
                        </div>
                    @endif
                </x-ui.card>

                {{-- Per-account versions and Google options --}}
                @foreach ($accounts->whereIn('ulid', $selected) as $account)
                    <x-ui.card wire:key="ver-{{ $account->ulid }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="font-medium text-ink"><span class="mr-1 inline-block size-2.5 rounded-full" style="background: {{ $account->network->color() }}"></span>{{ $account->displayName() }}</h2>
                            @if ($editable)
                                <label class="flex items-center gap-2 text-sm text-muted">
                                    <input type="checkbox" wire:model.live="customOn.{{ $account->ulid }}" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                                    Different text for {{ $account->network->shortLabel() }}
                                </label>
                            @endif
                        </div>
                        @if ($customOn[$account->ulid] ?? false)
                            <label for="custom-{{ $account->ulid }}" class="sr-only">Text for {{ $account->displayName() }}</label>
                            <textarea id="custom-{{ $account->ulid }}" wire:model.live.debounce.400ms="custom.{{ $account->ulid }}" rows="4" class="sh-input mt-3" placeholder="Leave empty to use the main text" @disabled(! $editable)></textarea>
                        @endif

                        @if ($account->network === $google)
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="gbp-topic-{{ $account->ulid }}" class="sh-label">Post type</label>
                                    <select id="gbp-topic-{{ $account->ulid }}" wire:model.live="gbp.{{ $account->ulid }}.topic" class="sh-input" @disabled(! $editable)>
                                        @foreach ($topics as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="gbp-button-{{ $account->ulid }}" class="sh-label">Button</label>
                                    <select id="gbp-button-{{ $account->ulid }}" wire:model.live="gbp.{{ $account->ulid }}.button" class="sh-input" @disabled(! $editable)>
                                        <option value="">{{ $link !== '' ? 'Learn more (default)' : 'No button' }}</option>
                                        @foreach ($buttons as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                    </select>
                                </div>
                                @if (($gbp[$account->ulid]['button'] ?? '') !== 'CALL' && ($gbp[$account->ulid]['button'] ?? '') !== '')
                                    <div class="sm:col-span-2">
                                        <label for="gbp-url-{{ $account->ulid }}" class="sh-label">Button link <span class="font-normal text-subtle">(defaults to the post's link)</span></label>
                                        <input id="gbp-url-{{ $account->ulid }}" type="url" wire:model="gbp.{{ $account->ulid }}.button_url" class="sh-input" placeholder="https://" @disabled(! $editable)>
                                        @error('gbp.'.$account->ulid.'.button_url') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                                @if (in_array($gbp[$account->ulid]['topic'] ?? 'STANDARD', ['OFFER', 'EVENT'], true))
                                    <div class="sm:col-span-2">
                                        <label for="gbp-title-{{ $account->ulid }}" class="sh-label">{{ ($gbp[$account->ulid]['topic'] ?? '') === 'OFFER' ? 'Offer' : 'Event' }} title</label>
                                        <input id="gbp-title-{{ $account->ulid }}" type="text" wire:model="gbp.{{ $account->ulid }}.title" class="sh-input" maxlength="58" @disabled(! $editable)>
                                    </div>
                                    <div>
                                        <label for="gbp-start-{{ $account->ulid }}" class="sh-label">Starts</label>
                                        <input id="gbp-start-{{ $account->ulid }}" type="date" wire:model="gbp.{{ $account->ulid }}.starts_on" class="sh-input" @disabled(! $editable)>
                                    </div>
                                    <div>
                                        <label for="gbp-end-{{ $account->ulid }}" class="sh-label">Ends</label>
                                        <input id="gbp-end-{{ $account->ulid }}" type="date" wire:model="gbp.{{ $account->ulid }}.ends_on" class="sh-input" @disabled(! $editable)>
                                        @error('gbp.'.$account->ulid.'.ends_on') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                    </div>
                                    @if (($gbp[$account->ulid]['topic'] ?? '') === 'OFFER')
                                        <div>
                                            <label for="gbp-coupon-{{ $account->ulid }}" class="sh-label">Coupon code <span class="font-normal text-subtle">(optional)</span></label>
                                            <input id="gbp-coupon-{{ $account->ulid }}" type="text" wire:model="gbp.{{ $account->ulid }}.coupon" class="sh-input" maxlength="58" @disabled(! $editable)>
                                        </div>
                                        <div>
                                            <label for="gbp-terms-{{ $account->ulid }}" class="sh-label">Terms <span class="font-normal text-subtle">(optional)</span></label>
                                            <input id="gbp-terms-{{ $account->ulid }}" type="text" wire:model="gbp.{{ $account->ulid }}.terms" class="sh-input" maxlength="500" @disabled(! $editable)>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        @endif
                    </x-ui.card>
                @endforeach

                {{-- When --}}
                @if ($editable)
                    <x-ui.card>
                        <h2 class="sh-label">When</h2>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="post-date" class="text-sm text-muted">Date</label>
                                <input id="post-date" type="date" wire:model="date" class="sh-input" min="{{ now($timezone)->format('Y-m-d') }}" max="{{ now($timezone)->addDays((int) config('social.max_schedule_days'))->format('Y-m-d') }}">
                            </div>
                            <div>
                                <label for="post-time" class="text-sm text-muted">Time ({{ $timezone }})</label>
                                <input id="post-time" type="time" wire:model="time" class="sh-input" step="300">
                            </div>
                        </div>
                        @error('date') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        @error('time') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        @error('post') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror

                        @if ($needsApproval)
                            <p class="mt-4 text-sm text-muted">An owner approves this post before it goes out. They're notified as soon as you send it.</p>
                        @endif
                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-ui.button wire:click="save('schedule')" wire:loading.attr="disabled" wire:target="save" icon="calendar">{{ $needsApproval ? 'Send for approval' : 'Schedule' }}</x-ui.button>
                            @unless ($needsApproval)
                                <x-ui.button variant="secondary" wire:click="save('now')" wire:loading.attr="disabled" wire:target="save" wire:confirm="Publish to {{ count($selected) }} {{ \Illuminate\Support\Str::plural('account', count($selected)) }} now?">Publish now</x-ui.button>
                            @endunless
                            <x-ui.button variant="ghost" wire:click="save('draft')" wire:loading.attr="disabled" wire:target="save">Save draft</x-ui.button>
                        </div>
                    </x-ui.card>
                @endif
            </div>

            {{-- Checks and previews --}}
            <aside class="space-y-4" aria-label="Checks and previews">
                @forelse ($accounts->whereIn('ulid', $selected) as $account)
                    @php($check = $checks[$account->ulid] ?? ['errors' => [], 'warnings' => []])
                    @php($text = $this->textFor($account->ulid))
                    @php($first = $mediaAssets->get($media[0] ?? ''))
                    <x-ui.card :padding="false" wire:key="prev-{{ $account->ulid }}" class="overflow-hidden">
                        <div class="flex items-center gap-2 border-b border-line px-4 py-2.5">
                            <span class="size-2.5 rounded-full" style="background: {{ $account->network->color() }}"></span>
                            <span class="truncate text-sm font-medium text-ink">{{ $account->displayName() }}</span>
                            @if ($check['errors'] !== [])
                                <x-ui.badge tone="danger" class="ml-auto">Fix {{ count($check['errors']) }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="success" class="ml-auto">Ready</x-ui.badge>
                            @endif
                        </div>
                        @if ($first)
                            <img src="{{ $first->url() }}" alt="" class="max-h-56 w-full object-cover">
                        @endif
                        <div class="px-4 py-3">
                            <p class="whitespace-pre-line break-words text-sm text-ink">{{ \Illuminate\Support\Str::limit($text, 600) ?: '…' }}</p>
                            @if ($link !== '' && $account->network->value !== 'instagram')
                                <p class="mt-2 truncate text-xs text-brand-300">{{ $link }}</p>
                            @endif
                            <p class="mt-2 text-xs text-subtle">{{ mb_strlen($text) }} / {{ number_format($account->network->capabilities()['max_chars']) }}</p>
                        </div>
                        @if ($check['errors'] !== [] || $check['warnings'] !== [])
                            <ul class="space-y-1 border-t border-line px-4 py-3 text-xs">
                                @foreach ($check['errors'] as $m)<li class="text-danger">{{ $m }}</li>@endforeach
                                @foreach ($check['warnings'] as $m)<li class="text-amber-300">{{ $m }}</li>@endforeach
                            </ul>
                        @endif
                    </x-ui.card>
                @empty
                    <x-ui.card><p class="text-sm text-muted">Choose where to post to see previews.</p></x-ui.card>
                @endforelse
            </aside>
        </div>
    @endif
</div>
