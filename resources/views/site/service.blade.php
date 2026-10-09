<x-site.layout :title="$service['nav']" :description="$service['summary'].' '.$service['intro']">
    <x-site.page-hero :eyebrow="$service['nav']" :title="$service['title']" :lead="$service['intro']">
        <a href="{{ route('site.contact', ['topic' => 'setup']) }}" class="btn-mint">Start your free setup</a>
        <a href="{{ route('site.contact', ['topic' => 'demo']) }}" class="btn-light">Book a demo</a>
    </x-site.page-hero>

    <section class="bg-white py-20 sm:py-28">
        <div class="site-container">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">What you get</h2>
                <p class="mt-4 text-lg text-slate-600">{{ $service['summary'] }}</p>
            </div>
            <dl class="mt-14 grid gap-x-8 gap-y-10 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($service['points'] as [$title, $text])
                    <div class="rounded-3xl border border-slate-200 p-7">
                        <dt class="flex items-center gap-3 font-semibold text-slate-900">
                            <span class="flex size-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><x-ui.icon name="check-circle" class="size-5" /></span>
                            {{ $title }}
                        </dt>
                        <dd class="mt-4 text-[15px] leading-7 text-slate-600">{{ $text }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    <section class="bg-slate-50 py-20 sm:py-24">
        <div class="site-container">
            <h2 class="text-2xl font-semibold tracking-tight text-slate-900">Works together with</h2>
            <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($others as $slug => $s)
                    <a href="{{ route('site.services.show', $slug) }}" class="group rounded-2xl bg-white p-6 ring-1 ring-slate-200 transition hover:ring-brand-300">
                        <x-ui.icon :name="$s['icon']" class="size-6 text-brand-600" />
                        <p class="mt-4 font-semibold text-slate-900">{{ $s['nav'] }}</p>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ $s['summary'] }}</p>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <x-site.cta />
</x-site.layout>
