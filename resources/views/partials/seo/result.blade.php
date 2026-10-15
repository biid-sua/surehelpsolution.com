{{-- One SEO task's result (D57), shared by the business's SEO page and the admin console. Needs $current. --}}
@php
    $out = $current->output ?? [];
    $types = \App\Services\Seo\SeoTaskCatalog::TYPES;
    $copy = fn (string $text) => 'navigator.clipboard.writeText('.\Illuminate\Support\Js::from($text).'); $dispatch(\'toast\', { type: \'success\', message: \'Copied\' })';
@endphp
@if ($current->type === 'keyword_plan')
    <p class="text-sm text-ink">{{ $out['summary'] ?? '' }}</p>
    <div class="mt-4 overflow-x-auto">
        <x-ui.table>
            <thead><tr><th scope="col">Search</th><th scope="col">Kind</th><th scope="col">Priority</th><th scope="col">Page to win it</th></tr></thead>
            <tbody class="divide-y divide-line">
                @foreach ($out['keywords'] ?? [] as $k)
                    <tr>
                        <td><p class="font-medium text-ink">{{ $k['keyword'] }}</p><p class="text-xs text-subtle">{{ $k['why'] }}</p></td>
                        <td class="text-muted">{{ ucfirst($k['intent']) }}</td>
                        <td><x-ui.badge :tone="['high' => 'brand', 'medium' => 'neutral', 'low' => 'neutral'][$k['priority']] ?? 'neutral'">{{ ucfirst($k['priority']) }}</x-ui.badge></td>
                        <td class="max-w-56 truncate text-muted" title="{{ $k['target_page'] }}">{{ $k['target_page'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    </div>
    @if (! empty($out['content_ideas']))
        <h3 class="mt-5 text-sm font-semibold text-ink">Content ideas</h3>
        <ul class="mt-2 space-y-2 text-sm">@foreach ($out['content_ideas'] as $idea)<li><span class="text-ink">{{ $idea['title'] }}</span> <span class="text-muted">: {{ $idea['why'] }}</span></li>@endforeach</ul>
    @endif
@elseif ($current->type === 'page_meta')
    @php $before = collect($current->input['pages'] ?? [])->keyBy('url'); @endphp
    <div class="space-y-4">
        @foreach ($out['pages'] ?? [] as $p)
            @php $old = $before[$p['url']] ?? []; @endphp
            <div class="rounded-xl border border-line p-4">
                <p class="truncate text-xs text-subtle">{{ $p['url'] }}</p>
                <dl class="mt-2 grid gap-2 text-sm sm:grid-cols-[7rem_minmax(0,1fr)_auto]">
                    @foreach (['title' => 'Title', 'description' => 'Description', 'h1' => 'Main heading'] as $f => $label)
                        <dt class="text-xs text-subtle">{{ $label }}</dt>
                        <dd>
                            <p class="text-ink">{{ $p[$f] }}</p>
                            <p class="text-xs text-subtle line-through">{{ $old[$f] ?? '(none)' }}</p>
                        </dd>
                        <dd><x-ui.button size="sm" variant="ghost" x-on:click="{{ $copy($p[$f]) }}">Copy</x-ui.button></dd>
                    @endforeach
                </dl>
                <p class="mt-2 text-xs text-muted">{{ $p['why'] }}</p>
            </div>
        @endforeach
    </div>
@elseif ($current->type === 'local_page')
    @php
        $html = '<h1>'.e($out['h1'] ?? '').'</h1>'."\n".'<p>'.e($out['intro'] ?? '').'</p>'."\n"
            .collect($out['sections'] ?? [])->map(fn ($s) => '<h2>'.e($s['heading']).'</h2>'."\n".collect(preg_split('/\n{2,}/', $s['body']))->map(fn ($para) => '<p>'.e(trim($para)).'</p>')->implode("\n"))->implode("\n")
            ."\n".'<h2>Questions</h2>'."\n".collect($out['faq'] ?? [])->map(fn ($q) => '<h3>'.e($q['question']).'</h3>'."\n".'<p>'.e($q['answer']).'</p>')->implode("\n")
            ."\n".'<p><strong>'.e($out['call_to_action'] ?? '').'</strong></p>'."\n\n".\App\Services\Seo\SeoTaskCatalog::faqJsonLd($out['faq'] ?? []);
    @endphp
    <dl class="grid gap-2 text-sm sm:grid-cols-[7rem_minmax(0,1fr)]">
        <dt class="text-xs text-subtle">Address</dt><dd class="text-ink">/{{ $out['slug'] ?? '' }}</dd>
        <dt class="text-xs text-subtle">Title</dt><dd class="text-ink">{{ $out['title'] ?? '' }}</dd>
        <dt class="text-xs text-subtle">Description</dt><dd class="text-ink">{{ $out['description'] ?? '' }}</dd>
    </dl>
    <article class="mt-4 space-y-3 rounded-xl bg-surface-2/50 p-4 text-sm text-ink">
        <h3 class="text-lg font-semibold">{{ $out['h1'] ?? '' }}</h3>
        <p class="whitespace-pre-line">{{ $out['intro'] ?? '' }}</p>
        @foreach ($out['sections'] ?? [] as $s)<h4 class="font-semibold">{{ $s['heading'] }}</h4><p class="whitespace-pre-line text-muted">{{ $s['body'] }}</p>@endforeach
        @foreach ($out['faq'] ?? [] as $q)<p><span class="font-medium">{{ $q['question'] }}</span><br><span class="text-muted">{{ $q['answer'] }}</span></p>@endforeach
        <p class="font-medium">{{ $out['call_to_action'] ?? '' }}</p>
    </article>
    <x-ui.button size="sm" variant="secondary" class="mt-3" x-on:click="{{ $copy($html) }}">Copy the page as HTML</x-ui.button>
@elseif ($current->type === 'faq')
    <div class="space-y-3 text-sm">@foreach ($out['faq'] ?? [] as $q)<div><p class="font-medium text-ink">{{ $q['question'] }}</p><p class="text-muted">{{ $q['answer'] }}</p></div>@endforeach</div>
    @php $ld = \App\Services\Seo\SeoTaskCatalog::faqJsonLd($out['faq'] ?? []); @endphp
    <p class="mt-4 text-xs text-subtle">Add this code to the page with the questions, so Google can show them in results:</p>
    <pre class="mt-1 max-h-48 overflow-auto rounded-lg bg-canvas p-3 text-xs text-muted">{{ $ld }}</pre>
    <x-ui.button size="sm" variant="secondary" class="mt-2" x-on:click="{{ $copy($ld) }}">Copy the code</x-ui.button>
@elseif ($current->type === 'gbp_posts')
    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($out['posts'] ?? [] as $post)
            <div class="rounded-xl border border-line p-4 text-sm">
                <p class="font-medium text-ink">{{ $post['title'] }}</p>
                <p class="mt-1 whitespace-pre-line text-muted">{{ $post['body'] }}</p>
                <p class="mt-2 text-xs text-subtle">Button: {{ str($post['button'])->replace('_', ' ')->title() }}</p>
                <x-ui.button size="sm" variant="ghost" class="mt-1" x-on:click="{{ $copy($post['title'].PHP_EOL.PHP_EOL.$post['body']) }}">Copy</x-ui.button>
            </div>
        @endforeach
    </div>
@endif

