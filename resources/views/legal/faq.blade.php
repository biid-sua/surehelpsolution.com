<x-site.layout title="Frequently asked questions" description="How SureHelp's live call answering works, what it costs, how calls are handled and how we protect your data.">
    <x-site.page-hero eyebrow="FAQ" title="Frequently asked questions" lead="How SureHelp works, what it costs and how we look after your callers and your data." />

    <section class="bg-white py-20 sm:py-24">
        <div class="site-container grid gap-12 lg:grid-cols-4">
            <nav class="hidden lg:block" aria-label="Topics">
                <ul class="sticky top-28 space-y-2 text-sm" role="list">
                    @foreach (array_keys(config('marketing.faqs')) as $heading)
                        <li><a href="#faq-{{ \Illuminate\Support\Str::slug($heading) }}" class="text-slate-600 hover:text-brand-600">{{ $heading }}</a></li>
                    @endforeach
                </ul>
            </nav>
            <div class="lg:col-span-3"><x-site.faq :groups="config('marketing.faqs')" /></div>
        </div>
    </section>

    <x-site.cta title="Still have a question?" lead="Ask us anything. A person replies within one business day, or call us now." />
</x-site.layout>
