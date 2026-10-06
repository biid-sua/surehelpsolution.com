<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 print:hidden">
        <a href="{{ $certificate->agent_user_id === auth()->id() ? route('agent.university', ['tab' => 'certificates']) : route('agent.training.certificates') }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><x-ui.icon name="arrow-left" class="size-4" /> Back</a>
        <x-ui.button variant="secondary" icon="download" x-on:click="window.print()">Print or save as PDF</x-ui.button>
    </div>

    @if ($certificate->effectiveStatus() !== 'active')
        <x-ui.alert :tone="$certificate->effectiveStatus() === 'expiring_soon' ? 'warning' : 'danger'" class="mb-6 print:hidden">
            @switch($certificate->effectiveStatus())
                @case('expiring_soon') This certification expires on {{ $certificate->expires_at->format('j M Y') }}. Take the course again to renew it. @break
                @case('expired') This certification expired on {{ $certificate->expires_at->format('j M Y') }}. @break
                @default This certification was revoked on {{ $certificate->revoked_at?->format('j M Y') }}{{ $certificate->revoke_reason ? ': '.$certificate->revoke_reason : '' }}.
            @endswitch
        </x-ui.alert>
    @endif

    <article class="mx-auto max-w-3xl rounded-2xl border-4 border-double border-brand-400/50 bg-surface p-10 text-center shadow-[var(--shadow-card)] print:border-black print:bg-white print:text-black print:shadow-none" aria-label="Certificate">
        <img src="{{ asset('assets/img/logo.png') }}" alt="SureHelp Solutions" class="mx-auto h-10 w-auto">
        <p class="mt-8 text-xs font-semibold uppercase tracking-[0.3em] text-subtle print:text-black">Certificate of completion</p>
        <p class="mt-6 text-sm text-muted print:text-black">This certifies that</p>
        <p class="mt-2 text-3xl font-semibold text-ink print:text-black">{{ $certificate->agent->name }}</p>
        <p class="mt-6 text-sm text-muted print:text-black">has completed the training and earned</p>
        <p class="mt-2 text-2xl font-semibold text-brand-200 print:text-black">{{ $certificate->name }}</p>
        <p class="mt-2 text-sm text-muted print:text-black">{{ $certificate->course->title }} · version {{ $certificate->course_version }}@if ($certificate->course->organization) · {{ $certificate->course->organization->name }}@endif</p>

        <dl class="mx-auto mt-10 grid max-w-xl grid-cols-1 gap-4 text-sm sm:grid-cols-3">
            <div><dt class="text-xs uppercase tracking-wider text-subtle print:text-black">Issued</dt><dd class="mt-1 text-ink print:text-black">{{ $certificate->issued_at->format('j F Y') }}</dd></div>
            <div><dt class="text-xs uppercase tracking-wider text-subtle print:text-black">Valid until</dt><dd class="mt-1 text-ink print:text-black">{{ $certificate->expires_at?->format('j F Y') ?? 'No expiry' }}</dd></div>
            <div><dt class="text-xs uppercase tracking-wider text-subtle print:text-black">Certificate ID</dt><dd class="mt-1 font-mono text-ink print:text-black">{{ $certificate->number }}</dd></div>
        </dl>
        <p class="mt-10 text-xs text-subtle print:text-black">Status: {{ $certificate->label() }} · SureHelp Solutions Agent University</p>
    </article>
</div>
