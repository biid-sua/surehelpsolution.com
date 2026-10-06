<div>
    <x-ui.page-header :title="$lesson['title']" :description="$course->title.' · '.$lesson['module'].' · lesson '.$position.' of '.$total" :back="route('agent.university.course', $course->ulid)" />

    @if (session('status'))
        <x-ui.alert tone="success" class="mb-6">{{ session('status') }}</x-ui.alert>
    @endif

    <div class="grid gap-6 xl:grid-cols-4">
        <div class="space-y-6 xl:col-span-3">
            <x-ui.card>
                @switch($type)
                    @case(\App\Enums\LessonType::Video)
                        @if ($embed = \App\Support\Training\VideoEmbed::url($lesson['url']))
                            <div class="aspect-video overflow-hidden rounded-xl bg-black">
                                <iframe src="{{ $embed }}" title="{{ $lesson['title'] }}" class="size-full" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
                            </div>
                        @elseif ($fileUrl)
                            <video controls preload="metadata" class="aspect-video w-full rounded-xl bg-black" src="{{ $fileUrl }}">
                                Your browser can't play this video. <a href="{{ $fileUrl }}">Download it</a>.
                            </video>
                        @elseif ($lesson['url'])
                            <x-ui.button :href="$lesson['url']" target="_blank" rel="noopener noreferrer" icon="chevron-right">Watch the video</x-ui.button>
                        @endif
                        @break
                    @case(\App\Enums\LessonType::Audio)
                        @if ($fileUrl)<audio controls preload="metadata" class="w-full" src="{{ $fileUrl }}"></audio>@endif
                        @break
                    @case(\App\Enums\LessonType::Pdf)
                        @if ($fileUrl)
                            <iframe src="{{ $fileUrl }}" title="{{ $lesson['title'] }}" class="h-[75vh] w-full rounded-xl border border-line bg-white"></iframe>
                            <a href="{{ $fileUrl }}" target="_blank" class="mt-3 inline-flex items-center gap-2 text-sm text-brand-300 hover:text-brand-200"><x-ui.icon name="download" class="size-4" /> Open the PDF in a new tab</a>
                        @endif
                        @break
                    @case(\App\Enums\LessonType::Document)
                    @case(\App\Enums\LessonType::Presentation)
                    @case(\App\Enums\LessonType::External)
                        <div class="flex flex-wrap gap-3">
                            @if ($fileUrl)
                                <x-ui.button :href="$fileUrl" icon="download">Download {{ $lesson['file']['name'] }}</x-ui.button>
                            @endif
                            @if ($lesson['url'])
                                <x-ui.button :href="$lesson['url']" :variant="$fileUrl ? 'secondary' : 'primary'" target="_blank" rel="noopener noreferrer" icon="chevron-right">Open {{ $type === \App\Enums\LessonType::External ? 'the resource' : 'online' }}</x-ui.button>
                            @endif
                        </div>
                        @if ($type === \App\Enums\LessonType::External)
                            <p class="mt-3 text-xs text-subtle">Opens another website in a new tab. Come back here to mark the lesson done.</p>
                        @endif
                        @break
                    @default
                @endswitch

                @if ($type !== \App\Enums\LessonType::Quiz && filled($lesson['body']))
                    <div @class(['sh-prose text-sm text-muted', 'mt-6' => $type !== \App\Enums\LessonType::Text])>{!! \Illuminate\Support\Str::markdown($lesson['body'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                @endif

                @if ($quiz)
                    @php($attempt = $quiz['attempt'])
                    @php($s = $quiz['settings'])
                    @if (! $attempt || $attempt->submitted_at)
                        @if ($attempt?->submitted_at)
                            <div @class(['mb-6 rounded-xl px-4 py-3 ring-1', 'bg-emerald-500/10 text-emerald-200 ring-emerald-400/30' => $attempt->passed, 'bg-red-500/10 text-red-200 ring-red-400/30' => ! $attempt->passed]) role="status">
                                <p class="text-base font-semibold">{{ $attempt->passed ? 'Passed' : 'Not passed yet' }}: {{ $attempt->score_percent }}%</p>
                                <p class="text-sm">You need {{ $s['pass_percent'] }}% to pass. {{ count(array_filter($attempt->results ?? [])) }} of {{ count($attempt->results ?? []) }} correct.</p>
                            </div>
                            @if (! $attempt->passed)
                                <div class="mb-6 space-y-3">
                                    @foreach ($quiz['questions'] as $i => $q)
                                        @unless ($attempt->results[$q['ulid']] ?? false)
                                            <div class="rounded-xl border border-line p-4 text-sm" wire:key="wrong-{{ $q['ulid'] }}">
                                                <p class="font-medium text-ink">Question {{ $i + 1 }}: {{ $q['prompt'] }}</p>
                                                @if ($q['explanation'])<p class="mt-1 text-muted">{{ $q['explanation'] }}</p>@else<p class="mt-1 text-subtle">Review the lessons before trying again.</p>@endif
                                            </div>
                                        @endunless
                                    @endforeach
                                </div>
                            @endif
                        @else
                            <h2 class="text-base font-semibold text-ink">Quiz</h2>
                            <p class="mt-1 text-sm text-muted">{{ $quiz['count'] }} {{ \Illuminate\Support\Str::plural('question', $quiz['count']) }} · pass mark {{ $s['pass_percent'] }}%@if ($quiz['left'] !== null) · {{ $quiz['left'] }} {{ \Illuminate\Support\Str::plural('attempt', $quiz['left']) }} left @endif</p>
                        @endif

                        @if ($isDone)
                            <p class="mt-4 text-sm text-emerald-300">You've passed this quiz.</p>
                        @elseif ($quiz['left'] === 0)
                            <x-ui.alert tone="warning" class="mt-4">You've used every attempt. Your supervisor can give you another one.</x-ui.alert>
                        @else
                            <x-ui.button wire:click="startQuiz" class="mt-4">{{ $attempt ? 'Try again' : 'Start the quiz' }}</x-ui.button>
                        @endif
                        @error('attempt')<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
                    @else
                        <form wire:submit="submitQuiz" class="space-y-6">
                            @foreach ($quiz['questions'] as $i => $q)
                                @php($many = $q['type'] === 'multiple')
                                <fieldset class="rounded-xl border border-line p-4" wire:key="q-{{ $q['ulid'] }}">
                                    <legend class="px-1 text-sm font-semibold text-ink">Question {{ $i + 1 }} of {{ count($quiz['questions']) }}</legend>
                                    @if ($q['scenario'])<p class="mb-3 rounded-lg bg-surface-2 px-3 py-2 text-sm text-muted">{{ $q['scenario'] }}</p>@endif
                                    <p class="text-sm text-ink">{{ $q['prompt'] }}</p>
                                    @if ($many)<p class="mt-1 text-xs text-subtle">Choose every answer that applies.</p>@endif
                                    <div class="mt-3 space-y-2">
                                        @foreach ($q['options'] as $o)
                                            <label class="flex cursor-pointer items-start gap-3 rounded-lg px-3 py-2 text-sm text-ink ring-1 ring-line hover:bg-surface-2">
                                                <input type="{{ $many ? 'checkbox' : 'radio' }}" wire:model="answers.{{ $q['ulid'] }}" value="{{ $o['id'] }}" name="q-{{ $q['ulid'] }}{{ $many ? '[]' : '' }}" class="mt-0.5">
                                                <span>{{ $o['label'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @error('answers.'.$q['ulid'])<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
                                </fieldset>
                            @endforeach
                            <x-ui.button type="submit" wire:loading.attr="disabled">Submit answers</x-ui.button>
                        </form>
                    @endif

                    @if ($quiz['history']->isNotEmpty())
                        <div class="mt-6 border-t border-line pt-4">
                            <h3 class="text-sm font-semibold text-ink">Your attempts</h3>
                            <ul class="mt-2 space-y-1 text-sm text-muted">
                                @foreach ($quiz['history'] as $h)
                                    <li>{{ $h->submitted_at->format('j M Y, g:i A') }}: {{ $h->score_percent }}% · {{ $h->passed ? 'passed' : 'not passed' }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endif
            </x-ui.card>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    @if ($previous)
                        <x-ui.button variant="ghost" :href="route('agent.university.lesson', [$course->ulid, $previous])" icon="chevron-left">Previous</x-ui.button>
                    @endif
                </div>
                <div class="flex gap-3">
                    @if ($type !== \App\Enums\LessonType::Quiz && ! $isDone)
                        <x-ui.button wire:click="complete" icon="check-circle">Mark as done and continue</x-ui.button>
                    @elseif ($isDone || ($quiz && $quiz['attempt']?->passed))
                        <x-ui.button wire:click="next">Continue</x-ui.button>
                    @elseif ($following)
                        <x-ui.button variant="secondary" :href="route('agent.university.lesson', [$course->ulid, $following])">Skip for now</x-ui.button>
                    @endif
                </div>
            </div>
        </div>

        <aside class="xl:col-span-1" aria-label="Course outline">
            <x-ui.card title="Outline" :padding="false">
                <div class="px-5 pt-3">
                    <div class="h-1.5 overflow-hidden rounded-full bg-surface-3" role="progressbar" aria-valuenow="{{ $assignment->progress_percent }}" aria-valuemin="0" aria-valuemax="100" aria-label="Course progress">
                        <div class="h-full rounded-full bg-brand-500" style="width: {{ $assignment->isDone() ? 100 : $assignment->progress_percent }}%"></div>
                    </div>
                </div>
                <ol class="space-y-3 px-5 py-4">
                    @foreach ($version->content['modules'] as $m => $module)
                        <li>
                            <p class="text-xs font-semibold uppercase tracking-wider text-subtle">{{ $module['title'] }}</p>
                            <ul class="mt-1 space-y-0.5">
                                @foreach ($module['lessons'] as $l)
                                    @php($here = $l['ulid'] === $lesson['ulid'])
                                    <li>
                                        <a href="{{ route('agent.university.lesson', [$course->ulid, $l['ulid']]) }}" @if ($here) aria-current="page" @endif
                                            @class(['flex items-center gap-2 rounded-md px-2 py-1 text-sm', 'bg-surface-3 text-ink' => $here, 'text-muted hover:text-ink' => ! $here])>
                                            <x-ui.icon :name="in_array($l['ulid'], $done, true) ? 'check-circle' : \App\Enums\LessonType::from($l['type'])->icon()" :class="in_array($l['ulid'], $done, true) ? 'size-4 shrink-0 text-emerald-300' : 'size-4 shrink-0'" />
                                            <span class="truncate">{{ $l['title'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>
        </aside>
    </div>
</div>
