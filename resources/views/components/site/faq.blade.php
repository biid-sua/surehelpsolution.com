@props(['groups'])
{{-- Accordions built on <details>: work without JavaScript and with a keyboard. --}}
<div class="space-y-12">
    @foreach ($groups as $heading => $items)
        <section aria-labelledby="faq-{{ \Illuminate\Support\Str::slug($heading) }}">
            @if (count($groups) > 1)<h2 id="faq-{{ \Illuminate\Support\Str::slug($heading) }}" class="mb-4 text-lg font-semibold text-slate-900">{{ $heading }}</h2>@endif
            <div class="divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white">
                @foreach ($items as [$question, $answer])
                    <details class="group px-6 py-5 [&_summary::-webkit-details-marker]:hidden">
                        <summary class="flex cursor-pointer list-none items-start justify-between gap-6 text-left text-base font-medium text-slate-900">
                            {{ $question }}
                            <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 transition group-open:rotate-45" aria-hidden="true">
                                <svg viewBox="0 0 20 20" fill="currentColor" class="size-4"><path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" /></svg>
                            </span>
                        </summary>
                        <p class="mt-3 max-w-3xl text-[15px] leading-7 text-slate-600">{{ $answer }}</p>
                    </details>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
