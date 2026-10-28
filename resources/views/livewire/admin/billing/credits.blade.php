<div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat label="Credits sold this month" :value="number_format($month['sold'])" :hint="\App\Support\Money::format($month['sold_cents']).' paid'" icon="card" />
        <x-ui.stat label="Credits used this month" :value="number_format($month['used'])" hint="beyond monthly allowances" icon="sparkles" />
        <x-ui.stat label="Credits held by businesses" :value="number_format($month['outstanding'])" hint="not used yet" icon="chart" />
        <x-ui.stat label="One credit" :value="number_format($tokensPerCredit)" hint="AI tokens" icon="info" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Packs on sale" description="What businesses can buy under Billing. Buying one issues an invoice; the credits arrive when it's paid.">
            <form wire:submit="savePacks" class="space-y-3">
                @foreach ($packs as $i => $pack)
                    <div class="flex items-end gap-3" wire:key="pk-{{ $i }}">
                        <div class="flex-1"><label for="pk-c-{{ $i }}" class="sh-label">Credits</label><input id="pk-c-{{ $i }}" type="number" min="1" wire:model="packs.{{ $i }}.credits" class="sh-input" @disabled(! $canManage)></div>
                        <div class="flex-1"><label for="pk-p-{{ $i }}" class="sh-label">Price ($)</label><input id="pk-p-{{ $i }}" type="text" inputmode="decimal" wire:model="packs.{{ $i }}.price" class="sh-input" @disabled(! $canManage)>@error("packs.$i.price") <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror</div>
                        @if ($canManage)<x-ui.button variant="ghost" size="sm" wire:click="removePack({{ $i }})">Remove</x-ui.button>@endif
                    </div>
                @endforeach
                @error('packs') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                @if ($canManage)
                    <div class="flex justify-between gap-2">
                        <x-ui.button variant="ghost" size="sm" wire:click="addPack">Add a pack</x-ui.button>
                        <x-ui.button type="submit" size="sm">Save packs</x-ui.button>
                    </div>
                @endif
            </form>
        </x-ui.card>

        @if ($canManage)
            <x-ui.card title="Give or take back credits" description="A gift, a goodwill credit or a refund. It shows in the business's credit history with your note.">
                <form wire:submit="give" class="grid gap-3">
                    <div><label for="gr-org" class="sh-label">Business</label>
                        <select id="gr-org" wire:model="grant.organization_id" class="sh-input"><option value="">Choose…</option>@foreach ($businesses as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
                        @error('grant.organization_id') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror</div>
                    <div><label for="gr-cr" class="sh-label">Credits <span class="font-normal text-subtle">(a minus number takes them back)</span></label><input id="gr-cr" type="number" wire:model="grant.credits" class="sh-input">
                        @error('grant.credits') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror</div>
                    <div><label for="gr-note" class="sh-label">Note</label><input id="gr-note" type="text" maxlength="200" wire:model="grant.note" class="sh-input" placeholder="e.g. Sorry for the outage on Oct 3">
                        @error('grant.note') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror</div>
                    <div class="flex justify-end"><x-ui.button type="submit" size="sm">Save</x-ui.button></div>
                </form>
            </x-ui.card>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Waiting for payment" :padding="false">
            @if ($pending->isEmpty())
                <p class="px-5 py-4 text-sm text-muted">No credit invoices waiting.</p>
            @else
                <ul class="divide-y divide-line" role="list">
                    @foreach ($pending as $p)
                        <li class="flex justify-between gap-3 px-5 py-3 text-sm" wire:key="pp-{{ $p->id }}"><span class="text-ink">{{ $names[$p->organization_id] ?? '—' }} · {{ number_format($p->credits) }} credits</span><span class="text-muted">{{ $p->invoice?->number }} · {{ \App\Support\Money::format($p->price_cents) }}</span></li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <x-ui.card title="Recent credit activity" :padding="false">
            @if ($entries->isEmpty())
                <p class="px-5 py-4 text-sm text-muted">Nothing yet.</p>
            @else
                <ul class="divide-y divide-line" role="list">
                    @foreach ($entries as $e)
                        <li class="flex items-baseline justify-between gap-3 px-5 py-3 text-sm" wire:key="en-{{ $e->id }}">
                            <span class="min-w-0"><span class="block truncate text-ink">{{ $names[$e->organization_id] ?? '—' }}</span><span class="block truncate text-xs text-muted">{{ $e->note }} · {{ $e->created_at->diffForHumans() }}@if ($e->kind === 'grant' && $e->user) · {{ $e->user->name }}@endif</span></span>
                            <span @class(['shrink-0 tabular-nums font-medium', 'text-success' => $e->credits > 0, 'text-ink' => $e->credits < 0])>{{ $e->credits > 0 ? '+' : '' }}{{ number_format($e->credits) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>
</div>
