@php
    $money = fn (int $cents) => \App\Support\Money::format($cents);
    $isEstimate = $type === 'estimate';
    $noun = $isEstimate ? 'estimate' : 'invoice';
@endphp
<div>
    <x-ui.page-header :title="$document ? $document->label() : 'New '.$noun" :description="$document ? ($document->customer?->fullName().' · '.$document->total()) : 'Write it, save it, then send it by text or email.'">
        <x-slot:actions>
            @if ($document)<x-ui.badge :tone="\App\Models\JobDocument::STATUS_TONES[$document->status] ?? 'neutral'">{{ \App\Models\JobDocument::STATUS_LABELS[$document->status] ?? $document->status }}</x-ui.badge>@endif
            <x-ui.button :href="route('app.documents.index', ['type' => $type])" wire:navigate variant="ghost" icon="arrow-left">All {{ $noun }}s</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <form wire:submit="save" class="space-y-6 lg:col-span-2">
            <x-ui.card title="Details">
                <div class="space-y-4 text-sm">
                    <div>
                        <span class="sh-label">Customer</span>
                        @if ($chosen)
                            <p class="flex flex-wrap items-center gap-2 text-ink">{{ $chosen->fullName() }} <span class="text-xs text-subtle">{{ collect([$chosen->displayPhone(), $chosen->email])->filter()->implode(' · ') }}</span>
                                @if (! $document)<button type="button" wire:click="$set('customer', '')" class="text-xs text-muted hover:text-ink">Change</button>@endif</p>
                        @else
                            <input type="search" wire:model.live.debounce.300ms="customerSearch" placeholder="Search by name, phone or email" aria-label="Find the customer" class="sh-input">
                            @if ($matches->isNotEmpty())
                                <ul class="mt-1 divide-y divide-line rounded-xl border border-line" role="list">
                                    @foreach ($matches as $match)
                                        <li><button type="button" wire:click="pickCustomer('{{ $match->ulid }}')" class="w-full px-3 py-2 text-left hover:bg-surface-2">{{ $match->fullName() }} <span class="text-xs text-subtle">{{ $match->displayPhone() }}</span></button></li>
                                    @endforeach
                                </ul>
                            @endif
                            @error('customer')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                        @endif
                    </div>
                    <div>
                        <label for="doc-title" class="sh-label">Title</label>
                        <input id="doc-title" type="text" wire:model="title" maxlength="150" class="sh-input" placeholder="Water heater replacement" @disabled(! $editable || ! $canWrite)>
                        @error('title')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="doc-date" class="sh-label">{{ $isEstimate ? 'Valid until' : 'Due on' }}</label>
                            <input id="doc-date" type="date" wire:model="date" class="sh-input" @disabled(! $editable || ! $canWrite)>
                        </div>
                        <div>
                            <label for="doc-tax" class="sh-label">Sales tax (%)</label>
                            <input id="doc-tax" type="text" inputmode="decimal" wire:model.live.debounce.400ms="taxRate" class="sh-input" placeholder="0" @disabled(! $editable || ! $canWrite)>
                            @error('taxRate')<p class="mt-1 text-danger">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card title="Lines">
                <div class="space-y-3 text-sm">
                    @foreach ($items as $i => $item)
                        <div wire:key="item-{{ $i }}" class="grid grid-cols-[1fr_4.5rem_6.5rem_auto] items-start gap-2">
                            <input type="text" wire:model="items.{{ $i }}.description" maxlength="300" aria-label="Description, line {{ $i + 1 }}" placeholder="What's included" class="sh-input" @disabled(! $editable || ! $canWrite)>
                            <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="items.{{ $i }}.quantity" aria-label="Quantity, line {{ $i + 1 }}" class="sh-input text-right" @disabled(! $editable || ! $canWrite)>
                            <div>
                                <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="items.{{ $i }}.price" aria-label="Price, line {{ $i + 1 }}" placeholder="$" class="sh-input text-right" @disabled(! $editable || ! $canWrite)>
                                @error('items.'.$i.'.price')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                            </div>
                            @if ($editable && $canWrite)
                                <button type="button" wire:click="removeItem({{ $i }})" class="mt-2 text-muted hover:text-danger" aria-label="Remove line {{ $i + 1 }}"><x-ui.icon name="x" class="size-4" /></button>
                            @else
                                <span></span>
                            @endif
                        </div>
                    @endforeach
                    @error('items')<p class="text-danger">{{ $message }}</p>@enderror
                    @if ($editable && $canWrite)<button type="button" wire:click="addItem" class="text-sm font-medium text-brand-300 hover:underline">+ Add a line</button>@endif
                    <dl class="ml-auto mt-2 w-full max-w-xs space-y-1 border-t border-line pt-3">
                        <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd class="text-ink">{{ $money($subtotal) }}</dd></div>
                        @if ($tax > 0)<div class="flex justify-between"><dt class="text-muted">Tax</dt><dd class="text-ink">{{ $money($tax) }}</dd></div>@endif
                        <div class="flex justify-between font-semibold"><dt class="text-ink">Total</dt><dd class="text-ink">{{ $money($subtotal + $tax) }}</dd></div>
                    </dl>
                </div>
            </x-ui.card>

            <x-ui.card title="Note to the customer">
                <textarea wire:model="notes" rows="3" maxlength="5000" aria-label="Note to the customer" class="sh-input" placeholder="What's included, warranty, how to pay…" @disabled(! $editable || ! $canWrite)></textarea>
            </x-ui.card>

            @if ($editable && $canWrite)
                <x-ui.button type="submit">{{ $document ? 'Save changes' : 'Save '.$noun }}</x-ui.button>
            @endif
        </form>

        <div class="space-y-6">
            @if ($document)
                @if ($canWrite && in_array($document->status, ['draft', 'sent'], true))
                    <x-ui.card :title="$document->status === 'draft' ? 'Send it' : 'Send it again'">
                        <form wire:submit="send" class="space-y-3 text-sm">
                            <label class="flex items-center gap-2 text-ink"><input type="checkbox" value="sms" wire:model="channels" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> By text</label>
                            <label class="flex items-center gap-2 text-ink"><input type="checkbox" value="email" wire:model="channels" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> By email</label>
                            @error('channels')<p class="text-danger">{{ $message }}</p>@enderror
                            <x-ui.button type="submit" icon="chat">Send</x-ui.button>
                            <p class="text-xs text-subtle">{{ $isEstimate ? 'They open a link, see the lines and accept by typing their name.' : ($paymentsReady ? 'They open a link and pay by card; the money goes to your Stripe account.' : 'They open a link to see it. Connect Stripe under Payments to let them pay by card.') }}</p>
                        </form>
                    </x-ui.card>
                @endif

                <x-ui.card title="What happened">
                    <ul class="space-y-2 text-sm" role="list">
                        <li class="text-muted">Made {{ $document->created_at->timezone($timezone)->format('M j, g:i A') }}</li>
                        @if ($document->sent_at)<li class="text-muted">Sent by {{ collect($document->sent_via)->map(fn ($c) => $c === 'sms' ? 'text' : 'email')->implode(' and ') }}, {{ $document->sent_at->diffForHumans() }}</li>@endif
                        @if ($document->viewed_at)<li class="text-muted">Opened by the customer {{ $document->viewed_at->diffForHumans() }}</li>@endif
                        @if ($document->accepted_at)<li class="text-success">Accepted by "{{ $document->accepted_name }}" {{ $document->accepted_at->diffForHumans() }}</li>@endif
                        @if ($document->declined_at)<li class="text-danger">Declined {{ $document->declined_at->diffForHumans() }}{{ $document->declined_reason ? ': "'.$document->declined_reason.'"' : '' }}</li>@endif
                        @if ($document->paid_at)<li class="text-success">Paid {{ $document->paid_how === 'card' ? 'by card' : '('.$document->paid_how.')' }} {{ $document->paid_at->diffForHumans() }}</li>@endif
                        @if ($document->estimate)<li><a href="{{ route('app.documents.edit', $document->estimate) }}" wire:navigate class="text-brand-300 hover:underline">From {{ $document->estimate->label() }}</a></li>@endif
                        @if ($invoice)<li><a href="{{ route('app.documents.edit', $invoice) }}" wire:navigate class="text-brand-300 hover:underline">Invoiced as {{ $invoice->label() }}</a></li>@endif
                    </ul>
                    @if ($document->status !== 'draft')
                        <div class="mt-4 flex gap-2" x-data>
                            <input type="text" readonly value="{{ $document->link() }}" aria-label="The customer's link" class="sh-input font-mono text-xs" x-on:focus="$el.select()">
                        </div>
                    @endif
                </x-ui.card>

                @if ($canWrite)
                    <x-ui.card title="Next">
                        <div class="space-y-3 text-sm">
                            @if ($isEstimate && in_array($document->status, ['draft', 'sent', 'accepted'], true))
                                <x-ui.button type="button" wire:click="makeInvoice" variant="secondary">Turn into an invoice</x-ui.button>
                            @endif
                            @if (! $isEstimate && in_array($document->status, ['draft', 'sent'], true))
                                <form wire:submit="markPaid" class="flex flex-wrap items-center gap-2">
                                    <select wire:model="paidHow" aria-label="How they paid" class="sh-input w-auto">
                                        <option value="cash">Cash</option><option value="check">Check</option><option value="transfer">Bank transfer</option><option value="other">Other</option>
                                    </select>
                                    <x-ui.button type="submit" variant="secondary">Mark paid</x-ui.button>
                                </form>
                            @endif
                            @if (in_array($document->status, ['draft', 'sent', 'accepted', 'declined'], true))
                                <button type="button" wire:click="void" wire:confirm="Void this {{ $noun }}? The customer can no longer accept or pay it." class="block text-muted hover:text-danger">Void</button>
                            @endif
                        </div>
                    </x-ui.card>
                @endif
            @endif
        </div>
    </div>
</div>
