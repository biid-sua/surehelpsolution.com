@php
    $stars = fn (int $n) => str_repeat('★', $n).str_repeat('☆', 5 - $n);
    $fmt = fn (?float $v) => $v === null ? '—' : number_format($v, 1);
@endphp
<div>
    <x-ui.page-header title="Reviews" description="Your Google reviews in one place: new ones reach you straight away, and you can reply from here.">
        @if ($accounts->isNotEmpty())
            <x-slot:actions><x-ui.button variant="secondary" size="sm" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh">Check now</x-ui.button></x-slot:actions>
        @endif
    </x-ui.page-header>

    @if (session('success'))<x-ui.alert tone="success" class="mb-6">{{ session('success') }}</x-ui.alert>@endif
    @if (session('error'))<x-ui.alert tone="danger" class="mb-6">{{ session('error') }}</x-ui.alert>@endif

    @if ($reconnect)
        <x-ui.alert tone="warning" class="mb-6" title="Google needs reconnecting">We lost access to your Business Profile, so new reviews aren't coming in.
            @if ($canConnect)<a href="{{ route('app.social.connect', ['connector' => 'google', 'back' => 'reviews']) }}" class="font-medium underline">Reconnect Google</a>@endif
        </x-ui.alert>
    @endif

    @if ($accounts->isEmpty() && ! $reconnect)
        <x-ui.card>
            <x-ui.empty-state icon="star" title="Connect your Google Business Profile" description="We'll bring in your reviews, tell you about new ones, flag low ratings as tasks, and let you reply without leaving SureHelp.">
                @if ($canConnect)
                    <x-ui.button class="mt-4" :href="route('app.social.connect', ['connector' => 'google', 'back' => 'reviews'])">Connect Google</x-ui.button>
                @else
                    <p class="mt-3 text-sm text-subtle">Ask the business owner to connect it.</p>
                @endif
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat label="Google rating" icon="star" :value="$fmt($summary['google_average'] ?? $summary['average'])" hint="{{ number_format($summary['google_total'] ?? $summary['count']) }} reviews on Google" />
            <x-ui.stat label="Last 30 days" icon="trend-up" :value="number_format($summary['recent'])" hint="{{ $summary['recent'] ? 'averaging '.$fmt($summary['recent_average']).' stars' : 'no new reviews' }}" />
            <x-ui.stat label="Waiting for a reply" icon="chat" :value="number_format($summary['waiting'])" hint="new reviews you haven't answered" />
            <x-ui.stat label="Replied to" icon="check-circle" :value="$summary['response_rate'] === null ? '—' : $summary['response_rate'].'%'" hint="of reviews in the last 12 months" />
        </div>

        <x-ui.card class="mb-6" title="Rating over time" description="Average stars of the reviews left each month. Gaps are months without reviews.">
            <div class="h-56" wire:ignore x-data="chart({ type: 'line', labels: @js(array_column($summary['months'], 'label')), series: [{ label: 'Average stars', data: @js(array_column($summary['months'], 'average')) }], precision: 1 })">
                <canvas x-ref="canvas" role="img" aria-label="Average review rating per month, last 12 months"></canvas>
            </div>
            <table class="sr-only">
                <caption>Reviews per month</caption>
                <tr><th>Month</th><th>Reviews</th><th>Average stars</th></tr>
                @foreach ($summary['months'] as $m)<tr><td>{{ $m['label'] }}</td><td>{{ $m['count'] }}</td><td>{{ $fmt($m['average']) }}</td></tr>@endforeach
            </table>
        </x-ui.card>

        <x-ui.card :padding="false">
            <div class="flex flex-wrap gap-1 border-b border-line px-4 pt-3" role="tablist">
                @foreach ($filters as $key => $label)
                    <button type="button" role="tab" wire:click="$set('filter', '{{ $key }}')" aria-selected="{{ $filter === $key ? 'true' : 'false' }}"
                        @class(['-mb-px border-b-2 px-3 py-2 text-sm font-medium', 'border-brand-400 text-ink' => $filter === $key, 'border-transparent text-muted hover:text-ink' => $filter !== $key])>{{ $label }}</button>
                @endforeach
            </div>
            @if ($reviews->isEmpty())
                <p class="px-5 py-6 text-sm text-muted">{{ $filter === 'waiting' ? 'Nothing waiting. Nice work.' : 'No reviews here yet.' }}</p>
            @else
                <ul class="divide-y divide-line" role="list">
                    @foreach ($reviews as $review)
                        <li class="px-5 py-4" wire:key="rv-{{ $review->id }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="text-sm font-medium text-ink">
                                    <span class="{{ $review->rating <= 2 ? 'text-danger' : ($review->rating === 3 ? 'text-warning' : 'text-success') }}" aria-label="{{ $review->rating }} out of 5 stars">{{ $stars($review->rating) }}</span>
                                    <span class="ml-2">{{ $review->authorLabel() }}</span>
                                </p>
                                <time class="text-xs text-subtle" datetime="{{ $review->reviewed_at->toIso8601String() }}">{{ $review->reviewed_at->setTimezone($timezone)->format('M j, Y') }}</time>
                            </div>
                            <p class="mt-1 whitespace-pre-line text-sm text-muted">{{ $review->comment ?: 'No comment, just a rating.' }}</p>

                            @if ($review->reply && $open !== $review->ulid)
                                <div class="mt-3 rounded-lg border-l-2 border-brand-400/60 bg-surface-2 px-3 py-2 text-sm">
                                    <p class="text-xs font-medium text-subtle">Your reply{{ $review->repliedBy ? ' by '.$review->repliedBy->name : '' }}{{ $review->replied_at ? ', '.$review->replied_at->setTimezone($timezone)->format('M j') : '' }}</p>
                                    <p class="mt-0.5 whitespace-pre-line text-ink">{{ $review->reply }}</p>
                                </div>
                            @endif

                            @if ($open === $review->ulid)
                                <div class="mt-3 space-y-2">
                                    <label for="reply-{{ $review->id }}" class="sh-label">Your public reply</label>
                                    <textarea id="reply-{{ $review->id }}" wire:model="text" rows="4" maxlength="4000" class="sh-input text-sm" placeholder="Thank you, {{ \Illuminate\Support\Str::before($review->authorLabel().' ', ' ') }}…"></textarea>
                                    @error('text') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                                    @error('reply') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                                    @error('draft') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                                    <p class="text-xs text-subtle">Everyone can see replies on Google. Never mention appointment, health or payment details.</p>
                                    <div class="flex flex-wrap gap-2">
                                        <x-ui.button size="sm" wire:click="post" wire:loading.attr="disabled" wire:target="post">{{ $review->reply ? 'Update reply' : 'Post reply' }}</x-ui.button>
                                        @if (! $draftBlocker)
                                            <x-ui.button size="sm" variant="secondary" wire:click="draft" wire:loading.attr="disabled" wire:target="draft">
                                                <span wire:loading.remove wire:target="draft">{{ $review->draft ? 'Draft again with AI' : 'Draft with AI' }}</span><span wire:loading wire:target="draft">Writing…</span>
                                            </x-ui.button>
                                        @endif
                                        <x-ui.button size="sm" variant="ghost" wire:click="close">Cancel</x-ui.button>
                                    </div>
                                    @if ($draftBlocker)<p class="text-xs text-subtle">{{ $draftBlocker }}</p>@endif
                                </div>
                            @elseif ($canManage)
                                <div class="mt-3 flex flex-wrap gap-3 text-sm">
                                    <button type="button" wire:click="compose('{{ $review->ulid }}')" class="font-medium text-brand-300 hover:underline">{{ $review->reply ? 'Edit reply' : 'Reply' }}</button>
                                    @if (! $review->reply)
                                        <button type="button" wire:click="dismiss('{{ $review->ulid }}')" class="text-muted hover:text-ink">{{ $review->status === 'dismissed' ? 'Move back to waiting' : 'No reply needed' }}</button>
                                    @endif
                                    @if ($review->error)<span class="text-xs text-danger">Last try failed: {{ $review->error }}</span>@endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div class="px-5 py-3">{{ $reviews->links() }}</div>
            @endif
        </x-ui.card>
    @endif
</div>
