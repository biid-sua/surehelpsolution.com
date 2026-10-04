<div>
    <x-ui.page-header title="Billing" description="Your plan, invoices and payments." />

    @if ($balance > 0)
        @php($first = $outstanding->first())
        <x-ui.card class="mb-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm text-muted">Amount due</p>
                    <p class="mt-1 text-3xl font-semibold text-ink">{{ $first->money($balance) }}</p>
                    <p @class(['mt-1 text-sm', 'font-semibold text-danger' => $first->isOverdue(), 'text-muted' => ! $first->isOverdue()])>
                        {{ $first->isOverdue() ? 'Overdue since' : 'Due' }} {{ $first->due_at->format('M j, Y') }} · {{ $outstanding->count() }} {{ \Illuminate\Support\Str::plural('invoice', $outstanding->count()) }}
                    </p>
                    @if ($first->client_reported_paid_at)
                        <p class="mt-2 text-sm text-emerald-300">You told us you paid {{ $first->client_reported_paid_at->diffForHumans() }}. We'll confirm it shortly.</p>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button variant="secondary" :href="route('app.billing.invoice', $first)">View invoice {{ $first->number }}</x-ui.button>
                    @if ($canManage && ! $first->client_reported_paid_at)
                        <x-ui.button variant="ghost" wire:click="startReport('{{ $first->ulid }}')">I've paid</x-ui.button>
                    @endif
                </div>
            </div>

            @if ($options !== [])
                <div class="mt-6 grid gap-4 md:grid-cols-2">
                    @foreach ($options as $option)
                        <div class="rounded-xl bg-surface-2 p-4 ring-1 ring-line">
                            <p class="font-medium text-ink">{{ $option->title }}</p>
                            <p class="mt-1 text-sm text-muted">{{ $option->description }}</p>
                            @if ($option->url)
                                <x-ui.button class="mt-3" :href="$option->url" target="_blank" rel="noopener" icon="card">Pay {{ $first->money($first->balanceCents()) }}</x-ui.button>
                            @endif
                            @if ($option->details)
                                <pre class="mt-3 whitespace-pre-wrap rounded-lg bg-surface p-3 font-mono text-xs text-ink ring-1 ring-line">{{ $option->details }}</pre>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($instructions)<p class="mt-3 text-xs text-subtle">{{ $instructions }}</p>@endif
            @else
                <p class="mt-4 text-sm text-muted">We'll email you how to pay this invoice.</p>
            @endif
        </x-ui.card>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Your plan" class="lg:col-span-1">
            @if ($subscription)
                <p class="text-xl font-semibold text-ink">{{ $subscription->plan->name }}</p>
                <p class="text-muted">{{ $subscription->priceLabel() }}</p>
                <p class="mt-2"><x-ui.badge :tone="$subscription->status->tone()">{{ $subscription->status->label() }}</x-ui.badge></p>
                <dl class="mt-4 space-y-2 text-sm">
                    @if ($subscription->status->value === 'trialing')
                        <div class="flex justify-between gap-2"><dt class="text-muted">Trial ends</dt><dd class="text-ink">{{ $subscription->trial_ends_on->format('M j, Y') }}</dd></div>
                    @endif
                    @if ($subscription->nextBillingDate())
                        <div class="flex justify-between gap-2"><dt class="text-muted">Next invoice</dt><dd class="text-ink">{{ $subscription->nextBillingDate()->format('M j, Y') }}</dd></div>
                    @endif
                    @if ($subscription->nextPlan)
                        <div class="flex justify-between gap-2"><dt class="text-muted">From next renewal</dt><dd class="text-ink">{{ $subscription->nextPlan->name }} ({{ $subscription->nextPlan->priceLabel() }})</dd></div>
                    @endif
                    @if ($subscription->cancel_at_period_end)
                        <div class="text-amber-300">Ends on {{ $subscription->current_period_end->format('M j, Y') }}.</div>
                    @endif
                </dl>
            @else
                <p class="text-sm text-muted">No plan yet. Choose one below and we'll set it up.</p>
            @endif
        </x-ui.card>

        <x-ui.card title="Invoices" :padding="false" class="lg:col-span-2">
            @if ($invoices->isEmpty())
                <x-ui.empty-state icon="card" title="No invoices yet" description="Your invoices appear here as soon as they're issued, ready to view or download." />
            @else
                <x-ui.table>
                    <thead><tr><th scope="col">Invoice</th><th scope="col">Period</th><th scope="col" class="text-right">Amount</th><th scope="col">Status</th><th scope="col" class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($invoices as $invoice)
                            <tr wire:key="inv-{{ $invoice->ulid }}">
                                <td class="font-mono text-sm text-ink">{{ $invoice->number }}<span class="block font-sans text-xs text-subtle">{{ $invoice->issued_at->format('M j, Y') }}</span></td>
                                <td class="text-sm text-muted">{{ $invoice->periodLabel() ?? '—' }}</td>
                                <td class="text-right tabular-nums text-ink">{{ $invoice->money($invoice->total_cents) }}</td>
                                <td><x-ui.badge :tone="$invoice->isOverdue() ? 'danger' : $invoice->status->tone()">{{ $invoice->isOverdue() ? 'Overdue' : $invoice->status->label() }}</x-ui.badge></td>
                                <td class="whitespace-nowrap text-right">
                                    <x-ui.button variant="ghost" size="sm" :href="route('app.billing.invoice', $invoice)">View</x-ui.button>
                                    <x-ui.button variant="ghost" size="sm" icon="download" :href="route('app.billing.invoice.pdf', $invoice)">PDF</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
                <x-ui.pagination :paginator="$invoices" />
            @endif
        </x-ui.card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Payment history" :padding="false" class="lg:col-span-1">
            @forelse ($payments as $payment)
                <div class="flex items-center justify-between gap-3 border-b border-line px-5 py-3 text-sm last:border-0">
                    <span><span class="block text-ink">{{ $payment->received_at->format('M j, Y') }}</span><span class="block text-xs text-subtle">{{ $payment->method->label() }}{{ $payment->invoice ? ' · '.$payment->invoice->number : '' }}</span></span>
                    <span class="tabular-nums text-ink">{{ $payment->amountLabel() }}</span>
                </div>
            @empty
                <p class="px-5 py-6 text-sm text-muted">No payments yet.</p>
            @endforelse
        </x-ui.card>

        @if ($plans->isNotEmpty())
            <x-ui.card title="Plans" class="lg:col-span-2">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($plans as $plan)
                        @php($isCurrent = $subscription && $subscription->plan_id === $plan->id)
                        <div @class(['rounded-xl p-4 ring-1', 'bg-brand-500/10 ring-brand-500/40' => $isCurrent, 'bg-surface-2 ring-line' => ! $isCurrent])>
                            <p class="font-semibold text-ink">{{ $plan->name }}</p>
                            <p class="text-lg text-ink">{{ $plan->priceLabel() }}</p>
                            @if ($plan->description)<p class="mt-2 whitespace-pre-line text-sm text-muted">{{ $plan->description }}</p>@endif
                            @if ($plan->trial_days > 0 && ! $subscription)<p class="mt-2 text-xs text-emerald-300">{{ $plan->trial_days }}-day free trial</p>@endif
                            @if ($isCurrent)
                                <p class="mt-3 text-sm font-medium text-brand-300">Your plan</p>
                            @elseif ($canManage)
                                <x-ui.button class="mt-3" size="sm" variant="secondary" wire:click="requestPlan({{ $plan->id }})" wire:confirm="Ask us to {{ $subscription ? 'switch you to' : 'start' }} {{ $plan->name }}?">{{ $subscription ? 'Switch to this plan' : 'Choose this plan' }}</x-ui.button>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($subscription)<p class="mt-3 text-xs text-subtle">Plan changes start at your next renewal, so you're never charged twice.</p>@endif
            </x-ui.card>
        @endif
    </div>

    <div x-data x-show="$wire.reporting !== null" x-cloak x-on:keydown.escape.window="$wire.set('reporting', null)"
        class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="paid-title">
        <div class="fixed inset-0 bg-black/60" x-on:click="$wire.set('reporting', null)"></div>
        <form wire:submit="report" class="relative w-full max-w-md rounded-2xl border border-line-strong bg-surface p-6 shadow-2xl">
            <h2 id="paid-title" class="text-lg font-semibold text-ink">Tell us you've paid</h2>
            <p class="mt-1 text-sm text-muted">We'll match it in our Payoneer account and mark the invoice paid.</p>
            <label for="pay-note" class="sh-label mt-4">How and when did you pay?</label>
            <textarea id="pay-note" wire:model="paymentNote" rows="3" class="sh-input" placeholder="e.g. Card through the Payoneer link on Oct 3, confirmation PX-12345"></textarea>
            @error('paymentNote') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
            <div class="mt-5 flex justify-end gap-2">
                <x-ui.button variant="secondary" x-on:click="$wire.set('reporting', null)">Cancel</x-ui.button>
                <x-ui.button type="submit">Send</x-ui.button>
            </div>
        </form>
    </div>
</div>
