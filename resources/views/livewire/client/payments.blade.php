@php($money = fn (int $c) => \App\Support\Money::format($c))
<div>
    <x-ui.page-header title="Payments" description="Deposits and pay-by-text: your customers pay by card through a secure link, straight into your own Stripe account.">
        @if ($ready && $canRequest)
            <x-slot:actions><x-ui.button size="sm" wire:click="compose">Request a payment</x-ui.button></x-slot:actions>
        @endif
    </x-ui.page-header>

    @if (session('error'))<x-ui.alert tone="danger" class="mb-6">{{ session('error') }}</x-ui.alert>@endif

    @if (! $allowed)
        <x-ui.card>
            <x-ui.empty-state icon="card" title="Take deposits and payments by text" description="Ask for a deposit when customers book online, and send a pay link after the job. Customers pay by card in seconds, and the money goes straight to your Stripe account. It's included in higher plans.">
                @can('billing.view', app(\App\Support\Tenancy\CurrentOrganization::class)->get())
                    <x-ui.button class="mt-4" :href="route('app.billing')">See plans</x-ui.button>
                @endcan
            </x-ui.empty-state>
        </x-ui.card>
    @elseif (! $available)
        <x-ui.alert tone="info" title="Coming soon">Card payments aren't switched on for SureHelp yet. We'll let you know when you can connect Stripe.</x-ui.alert>
    @elseif (! $account || ! $account->charges_enabled)
        <x-ui.card>
            <x-ui.empty-state icon="card" :title="$account ? 'Finish setting up Stripe' : 'Connect Stripe to get paid'"
                :description="$account ? 'Stripe still needs a few details before you can take payments (it usually takes a few minutes).' : 'Stripe handles the cards and pays you out to your bank. You can use an existing Stripe account or create one; it takes about 5 minutes.'">
                @if ($canManage)
                    <x-ui.button class="mt-4" :href="route('app.payments.connect')">{{ $account ? 'Continue with Stripe' : 'Connect Stripe' }}</x-ui.button>
                @else
                    <p class="mt-3 text-sm text-subtle">Ask the business owner to connect Stripe.</p>
                @endif
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <x-ui.stat label="Collected this month" icon="card" :value="$money($summary['collected'])" hint="{{ number_format($summary['paid']) }} {{ \Illuminate\Support\Str::plural('payment', $summary['paid']) }}" />
            <x-ui.stat label="Waiting to be paid" icon="clock" :value="$money($summary['open_cents'])" hint="{{ number_format($summary['open']) }} open {{ \Illuminate\Support\Str::plural('request', $summary['open']) }}" />
            <x-ui.stat label="Stripe" icon="check-circle" value="Connected" hint="Payouts and refunds are in your Stripe dashboard" />
        </div>

        @if ($composing)
            <x-ui.card class="mb-6" title="Request a payment">
                <form wire:submit="send" class="space-y-4">
                    <div>
                        <span class="sh-label">Customer</span>
                        @if ($chosen)
                            <div class="flex items-center gap-3 rounded-lg bg-surface-2 px-3 py-2 text-sm">
                                <span class="flex-1 text-ink">{{ $chosen->fullName() }} <span class="text-subtle">{{ collect([\App\Support\Phone::display($chosen->phone_e164), $chosen->email])->filter()->implode(' · ') }}</span></span>
                                <button type="button" wire:click="$set('customer', '')" class="text-muted hover:text-ink">Change</button>
                            </div>
                        @else
                            <label for="p-search" class="sr-only">Find a customer</label>
                            <input id="p-search" type="search" wire:model.live.debounce.300ms="search" class="sh-input" placeholder="Name, phone or email" autocomplete="off">
                            @if ($matches->isNotEmpty())
                                <ul class="mt-1 divide-y divide-line rounded-lg border border-line" role="list">
                                    @foreach ($matches as $c)
                                        <li><button type="button" wire:click="pick('{{ $c->ulid }}')" class="w-full px-3 py-2 text-left text-sm text-ink hover:bg-surface-2">{{ $c->fullName() }} <span class="text-subtle">{{ collect([\App\Support\Phone::display($c->phone_e164), $c->email])->filter()->implode(' · ') }}</span></button></li>
                                    @endforeach
                                </ul>
                            @endif
                        @endif
                        @error('customer') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-4 sm:grid-cols-[10rem_minmax(0,1fr)]">
                        <div>
                            <label for="p-amount" class="sh-label">Amount ($)</label>
                            <input id="p-amount" type="text" inputmode="decimal" wire:model="amount" class="sh-input" placeholder="150">
                            @error('amount') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="p-desc" class="sh-label">What it's for</label>
                            <input id="p-desc" type="text" wire:model="description" maxlength="200" class="sh-input" placeholder="Water heater repair, Oct 12">
                            @error('description') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <fieldset>
                        <legend class="sh-label">Send the link by</legend>
                        <div class="flex gap-5 text-sm text-ink">
                            <label class="flex items-center gap-2"><input type="checkbox" value="sms" wire:model="channels" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Text</label>
                            <label class="flex items-center gap-2"><input type="checkbox" value="email" wire:model="channels" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Email</label>
                        </div>
                        @error('channels') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </fieldset>
                    <div class="flex gap-2">
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="send">Send request</x-ui.button>
                        <x-ui.button variant="ghost" wire:click="$set('composing', false)">Cancel</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <x-ui.card :padding="false">
                <div class="flex flex-wrap gap-1 border-b border-line px-4 pt-3" role="tablist">
                    @foreach ($filters as $key => $label)
                        <button type="button" role="tab" wire:click="$set('filter', '{{ $key }}')" aria-selected="{{ $filter === $key ? 'true' : 'false' }}"
                            @class(['-mb-px border-b-2 px-3 py-2 text-sm font-medium', 'border-brand-400 text-ink' => $filter === $key, 'border-transparent text-muted hover:text-ink' => $filter !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
                @if ($requests->isEmpty())
                    <p class="px-5 py-6 text-sm text-muted">{{ $filter === 'open' ? 'Nothing waiting to be paid.' : 'No payments yet.' }}</p>
                @else
                    <ul class="divide-y divide-line" role="list">
                        @foreach ($requests as $p)
                            <li class="flex flex-wrap items-start gap-3 px-5 py-4" wire:key="pr-{{ $p->id }}">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-ink">{{ $p->amount() }} · {{ $p->description }}</p>
                                    <p class="text-xs text-subtle">
                                        @if ($p->customer)<a href="{{ route('app.customers.show', $p->customer->ulid) }}" class="hover:text-ink">{{ $p->customer->fullName() }}</a> · @endif
                                        {{ $p->kind === 'deposit' ? 'Deposit for an online booking' : 'Payment request' }} · {{ $p->created_at->setTimezone($timezone)->format('M j, g:i A') }}
                                        @if ($p->sent_via) · sent by {{ implode(' and ', array_map(fn ($c) => $c === 'sms' ? 'text' : 'email', $p->sent_via)) }}@endif
                                        @if ($p->paid_at) · paid {{ $p->paid_at->setTimezone($timezone)->format('M j') }}@endif
                                    </p>
                                </div>
                                <x-ui.badge :tone="['open' => 'warning', 'paid' => 'success', 'refunded' => 'neutral', 'cancelled' => 'neutral'][$p->status] ?? 'neutral'">{{ ['open' => 'Waiting', 'paid' => 'Paid', 'refunded' => 'Refunded', 'cancelled' => 'Cancelled'][$p->status] ?? $p->status }}</x-ui.badge>
                                @if ($p->status === 'open')
                                    <div class="flex w-full flex-wrap gap-3 text-sm" x-data="{ copied: false }">
                                        <button type="button" class="text-brand-300 hover:underline" x-on:click="navigator.clipboard.writeText(@js($p->link())); copied = true; setTimeout(() => copied = false, 2000)"><span x-show="!copied">Copy link</span><span x-show="copied" x-cloak>Copied</span></button>
                                        @if ($canRequest)<button type="button" wire:click="resend('{{ $p->ulid }}')" class="text-muted hover:text-ink">Send again</button>@endif
                                        @if ($canManage)<button type="button" wire:click="cancel('{{ $p->ulid }}')" wire:confirm="Cancel this request? The link will stop working." class="text-muted hover:text-danger">Cancel</button>@endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <div class="px-5 py-3">{{ $requests->links() }}</div>
                @endif
            </x-ui.card>

            <x-ui.card title="Deposits for online bookings" description="Customers who book on your website or link page are asked to pay a deposit. It cuts no-shows.">
                <form wire:submit="saveDeposit" class="space-y-4">
                    <fieldset @disabled(! $canManage) class="space-y-4">
                        <label class="flex items-center gap-3 text-sm text-ink">
                            <input type="checkbox" wire:model.live="depositEnabled" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Ask for a deposit
                        </label>
                        @if ($depositEnabled)
                            <div>
                                <label for="p-deposit" class="sh-label">Deposit ($)</label>
                                <input id="p-deposit" type="text" inputmode="decimal" wire:model="depositAmount" class="sh-input w-32" placeholder="50">
                                @error('depositAmount') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                <p class="mt-1 text-xs text-subtle">The pay link appears right after they book, and is sent by email and text. The booking isn't held back if they don't pay; you'll see who has.</p>
                            </div>
                        @endif
                    </fieldset>
                    @if ($canManage)<x-ui.button type="submit" size="sm" variant="secondary">Save</x-ui.button>@endif
                </form>
            </x-ui.card>
        </div>
    @endif
</div>
