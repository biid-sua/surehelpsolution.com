<x-auth-layout title="Before you continue" :description="$updated ? 'We updated some of our terms. Please read and accept them to keep using SureHelp.' : 'Please read and accept these to use SureHelp.'">
    <form method="POST" action="{{ route('account.terms.accept') }}" class="mt-6 space-y-4">
        @csrf
        <ul class="space-y-2" role="list">
            @foreach ($documents as $key => $document)
                <li class="flex items-center justify-between gap-3 rounded-xl bg-surface-2 px-4 py-3 ring-1 ring-line">
                    <span class="text-sm font-medium text-ink">{{ $document['label'] }} <span class="font-normal text-subtle">· version {{ $document['version'] }}</span></span>
                    <a href="{{ route($document['route']) }}" target="_blank" rel="noopener" class="shrink-0 text-sm text-brand-300 hover:underline">Read</a>
                </li>
            @endforeach
        </ul>
        <label class="flex items-start gap-3 text-sm text-ink">
            <input type="checkbox" name="accept" value="1" required class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
            <span>I have read and accept the {{ collect($documents)->pluck('label')->join(', ', ' and ') }}.</span>
        </label>
        <x-ui.button type="submit" class="w-full">Accept and continue</x-ui.button>
    </form>

    <x-slot:footer>
        <a href="{{ route('auth.logout') }}" class="text-brand-300 hover:underline">Sign out</a>
    </x-slot:footer>
</x-auth-layout>
