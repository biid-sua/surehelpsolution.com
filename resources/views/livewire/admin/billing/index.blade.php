<div>
    <x-ui.page-header title="Billing" description="Plans, subscriptions and invoices. Clients pay through Payoneer; record each payment here when it lands." />

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <x-ui.stat label="Monthly recurring" icon="trend-up" :value="\App\Support\Money::format($metrics['mrr'])" hint="active plans" />
        <x-ui.stat label="Outstanding" icon="card" :value="\App\Support\Money::format($metrics['outstanding'])" hint="unpaid invoices" />
        <x-ui.stat label="Overdue" icon="alert" :value="number_format($metrics['overdue'])" hint="past due date" />
        <x-ui.stat label="Collected" icon="check-circle" :value="\App\Support\Money::format($metrics['collected'])" hint="this month" />
        <x-ui.stat label="Reported paid" icon="inbox" :value="number_format($metrics['reported'])" hint="to confirm" />
    </div>

    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-line" role="tablist" aria-label="Billing sections">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                @class(['-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium', 'border-brand-400 text-ink' => $tab === $key, 'border-transparent text-muted hover:text-ink' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'invoices')
        <div class="grid gap-6 xl:grid-cols-5">
            <div class="xl:col-span-3">
                <div class="mb-3 flex flex-wrap gap-2">
                    @foreach (['attention' => 'Needs attention', 'open' => 'Unpaid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'void' => 'Void', 'all' => 'All'] as $key => $label)
                        <button type="button" wire:click="$set('filter', '{{ $key }}')" @class(['rounded-full px-3 py-1 text-sm ring-1', 'bg-brand-600 text-white ring-brand-500' => $filter === $key, 'bg-surface-2 text-muted ring-line hover:text-ink' => $filter !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
                <x-ui.card :padding="false">
                    @if ($invoices->isEmpty())
                        <x-ui.empty-state icon="card" title="{{ $filter === 'attention' ? 'Nothing needs you' : 'No invoices here' }}" description="{{ $filter === 'attention' ? 'No overdue invoices, no payments to confirm, and every invoice has a way to pay.' : 'Try another filter.' }}" />
                    @else
                        <x-ui.table>
                            <thead><tr><th scope="col">Invoice</th><th scope="col">Business</th><th scope="col" class="text-right">Balance</th><th scope="col">Status</th></tr></thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($invoices as $invoice)
                                    <tr wire:key="ai-{{ $invoice->ulid }}" wire:click="select('{{ $invoice->ulid }}')" @class(['cursor-pointer hover:bg-surface-2', 'bg-surface-2' => $selected === $invoice->ulid])>
                                        <td class="font-mono text-sm text-ink">{{ $invoice->number }}<span class="block font-sans text-xs text-subtle">due {{ $invoice->due_at->format('M j') }}</span></td>
                                        <td class="text-sm text-ink">{{ $invoice->organization->name ?? '—' }}</td>
                                        <td class="text-right tabular-nums text-ink">{{ $invoice->money($invoice->status->value === 'open' ? $invoice->balanceCents() : $invoice->total_cents) }}</td>
                                        <td class="space-x-1">
                                            <x-ui.badge :tone="$invoice->isOverdue() ? 'danger' : $invoice->status->tone()">{{ $invoice->isOverdue() ? 'Overdue' : $invoice->status->label() }}</x-ui.badge>
                                            @if ($invoice->client_reported_paid_at && $invoice->status->value === 'open')<x-ui.badge tone="info">Reported paid</x-ui.badge>@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-ui.table>
                        <x-ui.pagination :paginator="$invoices" />
                    @endif
                </x-ui.card>

                @if ($canManage)
                    <x-ui.card class="mt-6" title="One-off invoice" description="Setup fees, extra work, anything outside a plan.">
                        <form wire:submit="issueOneOff" class="grid gap-3 sm:grid-cols-[1fr_1fr_8rem_auto] sm:items-end">
                            <div>
                                <label for="oo-org" class="sh-label">Business</label>
                                <select id="oo-org" wire:model="oneOff.organization_id" class="sh-input"><option value="">Choose…</option>@foreach ($organizations as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select>
                                @error('oneOff.organization_id') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="oo-desc" class="sh-label">Description</label>
                                <input id="oo-desc" type="text" wire:model="oneOff.description" class="sh-input" placeholder="Onboarding and script setup">
                                @error('oneOff.description') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="oo-amount" class="sh-label">Amount ($)</label>
                                <input id="oo-amount" type="text" inputmode="decimal" wire:model="oneOff.amount" class="sh-input" placeholder="150">
                                @error('oneOff.amount') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                            </div>
                            <x-ui.button type="submit" variant="secondary">Issue</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif
            </div>

            <div class="xl:col-span-2">
                @if ($selectedInvoice)
                    @php($inv = $selectedInvoice)
                    <x-ui.card>
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p class="font-mono text-ink">{{ $inv->number }}</p>
                                <p class="text-sm text-muted">{{ $inv->organization->name ?? '' }} · issued {{ $inv->issued_at->format('M j, Y') }} · due {{ $inv->due_at->format('M j, Y') }}</p>
                            </div>
                            <x-ui.badge :tone="$inv->isOverdue() ? 'danger' : $inv->status->tone()">{{ $inv->isOverdue() ? 'Overdue' : $inv->status->label() }}</x-ui.badge>
                        </div>
                        <ul class="mt-4 divide-y divide-line text-sm">
                            @foreach ($inv->items as $item)<li class="flex justify-between gap-3 py-2"><span class="text-ink">{{ $item->description }}</span><span class="tabular-nums">{{ $inv->money($item->amount_cents) }}</span></li>@endforeach
                            <li class="flex justify-between gap-3 py-2 font-semibold"><span>Total</span><span class="tabular-nums">{{ $inv->money($inv->total_cents) }}</span></li>
                            @if ($inv->amount_paid_cents)<li class="flex justify-between gap-3 py-2"><span>Balance</span><span class="tabular-nums">{{ $inv->money($inv->balanceCents()) }}</span></li>@endif
                        </ul>

                        @if ($inv->client_reported_paid_at && $inv->status->value === 'open')
                            <x-ui.alert tone="info" class="mt-4" title="Client reported payment {{ $inv->client_reported_paid_at->diffForHumans() }}">{{ $inv->client_payment_note ?: 'No details given.' }} Check Payoneer, then record it below.</x-ui.alert>
                        @endif

                        @foreach ($inv->payments as $p)
                            <p class="mt-2 text-sm text-muted">{{ $p->received_at->format('M j, Y') }} · {{ $p->method->label() }} · {{ $p->amountLabel() }}{{ $p->reference ? ' · '.$p->reference : '' }}{{ $p->recordedBy ? ' · by '.$p->recordedBy->name : '' }}</p>
                        @endforeach

                        @if ($canManage && $inv->status->value === 'open')
                            <form wire:submit="savePaymentLink" class="mt-5">
                                <label for="pay-link" class="sh-label">Payoneer payment link for this invoice</label>
                                <div class="flex gap-2">
                                    <input id="pay-link" type="url" wire:model="paymentLink" class="sh-input" placeholder="https://link.payoneer.com/…">
                                    <x-ui.button type="submit" size="sm" variant="secondary">Save</x-ui.button>
                                </div>
                                @error('paymentLink') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                <p class="mt-1 text-xs text-subtle">In Payoneer: <em>Receive › Request a payment</em> for {{ $inv->money($inv->balanceCents()) }} with reference {{ $inv->number }}, then paste the link. Leave empty to use the default link from Payment settings.</p>
                            </form>

                            <form wire:submit="recordPayment" class="mt-5 grid gap-3 rounded-xl bg-surface-2 p-4 ring-1 ring-line sm:grid-cols-2">
                                <p class="sm:col-span-2 text-sm font-semibold text-ink">Record a payment</p>
                                <div>
                                    <label for="p-amount" class="sh-label">Amount ($)</label>
                                    <input id="p-amount" type="text" inputmode="decimal" wire:model="payment.amount" class="sh-input">
                                    @error('payment.amount') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="p-date" class="sh-label">Received on</label>
                                    <input id="p-date" type="date" wire:model="payment.received_on" class="sh-input" max="{{ now()->toDateString() }}">
                                    @error('payment.received_on') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="p-method" class="sh-label">Method</label>
                                    <select id="p-method" wire:model="payment.method" class="sh-input">@foreach ($methods as $m)<option value="{{ $m->value }}">{{ $m->label() }}</option>@endforeach</select>
                                </div>
                                <div>
                                    <label for="p-ref" class="sh-label">Payoneer transaction ID</label>
                                    <input id="p-ref" type="text" wire:model="payment.reference" class="sh-input">
                                    @error('payment.reference') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                </div>
                                <div class="sm:col-span-2 flex justify-end"><x-ui.button type="submit" size="sm">Record payment</x-ui.button></div>
                            </form>

                            <div class="mt-5 flex flex-wrap items-start gap-2">
                                <x-ui.button size="sm" variant="secondary" wire:click="resend">Email invoice again</x-ui.button>
                                <div x-data="{ open: false }" class="flex-1">
                                    <x-ui.button size="sm" variant="ghost" x-show="!open" x-on:click="open = true">Void…</x-ui.button>
                                    <form x-show="open" x-cloak wire:submit="void" class="flex gap-2">
                                        <input type="text" wire:model="voidReason" class="sh-input" placeholder="Why is it voided?" aria-label="Reason for voiding">
                                        <x-ui.button type="submit" size="sm" variant="secondary">Void</x-ui.button>
                                    </form>
                                    @error('voidReason') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @endif
                    </x-ui.card>
                @else
                    <x-ui.card><p class="text-sm text-muted">Select an invoice to see it, add a Payoneer link or record a payment.</p></x-ui.card>
                @endif
            </div>
        </div>
    @elseif ($tab === 'subscriptions')
        <x-ui.card :padding="false">
            @if ($subscriptions->isEmpty())
                <x-ui.empty-state icon="card" title="No subscriptions yet" description="Put a business on a plan below. Create plans first in the Plans tab." />
            @else
                <x-ui.table>
                    <thead><tr><th scope="col">Business</th><th scope="col">Plan</th><th scope="col">Status</th><th scope="col">Next invoice</th><th scope="col" class="text-right"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($subscriptions as $s)
                            <tr wire:key="sub-{{ $s->id }}">
                                <td class="text-ink">{{ $s->organization->name ?? '—' }}</td>
                                <td class="text-sm">{{ $s->plan->name }} <span class="text-muted">{{ $s->priceLabel() }}</span>@if ($s->nextPlan)<span class="block text-xs text-amber-300">→ {{ $s->nextPlan->name }} at renewal</span>@endif</td>
                                <td><x-ui.badge :tone="$s->status->tone()">{{ $s->status->label() }}</x-ui.badge>@if ($s->cancel_at_period_end)<span class="block text-xs text-amber-300">ends {{ $s->current_period_end->format('M j') }}</span>@endif</td>
                                <td class="text-sm text-muted">{{ $s->nextBillingDate()?->format('M j, Y') ?? '—' }}</td>
                                <td class="whitespace-nowrap text-right">
                                    @if ($canManage)
                                        <select class="sh-input inline-block w-40 py-1 text-sm" aria-label="Change plan for {{ $s->organization->name ?? '' }}" x-on:change="$wire.changePlan({{ $s->id }}, $event.target.value); $event.target.value = ''">
                                            <option value="">Change plan…</option>
                                            @foreach ($plans->where('is_active', true) as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->priceLabel() }})</option>@endforeach
                                        </select>
                                        @if ($s->cancel_at_period_end)
                                            <x-ui.button size="sm" variant="ghost" wire:click="resumeSubscription({{ $s->id }})">Keep</x-ui.button>
                                        @else
                                            <x-ui.button size="sm" variant="ghost" wire:click="cancelSubscription({{ $s->id }}, false)" wire:confirm="End this plan at the end of the current period?">Cancel at period end</x-ui.button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        @if ($canManage)
            <x-ui.card class="mt-6" title="Start a subscription">
                <form wire:submit="startSubscription" class="grid gap-3 sm:grid-cols-[1fr_1fr_10rem_auto_auto] sm:items-end">
                    <div>
                        <label for="s-org" class="sh-label">Business</label>
                        <select id="s-org" wire:model="subscribe.organization_id" class="sh-input"><option value="">Choose…</option>@foreach ($unsubscribed as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select>
                        @error('subscribe.organization_id') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="s-plan" class="sh-label">Plan</label>
                        <select id="s-plan" wire:model="subscribe.plan_id" class="sh-input"><option value="">Choose…</option>@foreach ($plans->where('is_active', true) as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->priceLabel() }}{{ $p->trial_days ? ', '.$p->trial_days.'-day trial' : '' }})</option>@endforeach</select>
                        @error('subscribe.plan_id') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="s-start" class="sh-label">Starts</label>
                        <input id="s-start" type="date" wire:model="subscribe.start_on" class="sh-input">
                    </div>
                    <label class="flex items-center gap-2 pb-2 text-sm text-ink"><input type="checkbox" wire:model="subscribe.trial" value="1" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Free trial</label>
                    <x-ui.button type="submit">Start</x-ui.button>
                </form>
                <p class="mt-2 text-xs text-subtle">Without a trial, the first invoice is issued and emailed right away. Renewals invoice automatically.</p>
            </x-ui.card>
        @endif
    @elseif ($tab === 'plans')
        <div class="grid gap-6 lg:grid-cols-5">
            <x-ui.card :padding="false" class="lg:col-span-3">
                @if ($plans->isEmpty())
                    <x-ui.empty-state icon="card" title="No plans yet" description="Create your first plan, e.g. Virtual Receptionist at $299 a month." />
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($plans as $p)
                            <li class="flex items-center justify-between gap-3 px-5 py-3" wire:key="plan-{{ $p->id }}">
                                <span>
                                    <span class="font-medium text-ink">{{ $p->name }}</span> <span class="text-muted">{{ $p->priceLabel() }}</span>
                                    <span class="block text-xs text-subtle">{{ $p->subscriptions_count }} {{ \Illuminate\Support\Str::plural('business', $p->subscriptions_count) }}{{ $p->trial_days ? ' · '.$p->trial_days.'-day trial' : '' }}{{ $p->is_public ? '' : ' · hidden' }}{{ $p->is_active ? '' : ' · retired' }}</span>
                                </span>
                                @if ($canManage)<x-ui.button size="sm" variant="ghost" wire:click="editPlan({{ $p->id }})">Edit</x-ui.button>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
            @if ($canManage)
                <x-ui.card class="lg:col-span-2" :title="$editingPlan ? 'Edit plan' : 'New plan'">
                    <form wire:submit="savePlan" class="grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2"><label for="pl-name" class="sh-label">Name</label><input id="pl-name" type="text" wire:model="plan.name" class="sh-input">@error('plan.name') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror</div>
                        <div><label for="pl-price" class="sh-label">Price ($)</label><input id="pl-price" type="text" inputmode="decimal" wire:model="plan.price" class="sh-input">@error('plan.price') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror</div>
                        <div><label for="pl-int" class="sh-label">Billed</label><select id="pl-int" wire:model="plan.interval" class="sh-input">@foreach (\App\Models\Plan::INTERVALS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                        <div><label for="pl-trial" class="sh-label">Trial days</label><input id="pl-trial" type="number" min="0" max="90" wire:model="plan.trial_days" class="sh-input">@error('plan.trial_days') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror</div>
                        <div class="sm:col-span-2"><label for="pl-desc" class="sh-label">What's included</label><textarea id="pl-desc" wire:model="plan.description" rows="3" class="sh-input"></textarea></div>
                        <div class="sm:col-span-2"><label for="pl-feat" class="sh-label">Feature keys <span class="font-normal text-subtle">(comma-separated, for feature access)</span></label><input id="pl-feat" type="text" wire:model="plan.features" class="sh-input" placeholder="calendar_sync, ai.website_chatbot"></div>
                        <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" wire:model="plan.is_public" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Shown to clients</label>
                        <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" wire:model="plan.is_active" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Available</label>
                        <div class="sm:col-span-2 flex justify-end gap-2">
                            @if ($editingPlan)<x-ui.button variant="ghost" size="sm" wire:click="cancelPlanEdit">Cancel</x-ui.button>@endif
                            <x-ui.button type="submit" size="sm">Save plan</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif
        </div>
    @else
        <x-ui.card title="Payment settings" description="What clients see on invoices and the billing page.">
            <form wire:submit="saveSettings" class="grid gap-4 lg:grid-cols-2">
                <div><label for="st-name" class="sh-label">Company name on invoices</label><input id="st-name" type="text" wire:model="settings.company_name" class="sh-input" @disabled(! $canManage)>@error('settings.company_name') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror</div>
                <div><label for="st-email" class="sh-label">Billing email</label><input id="st-email" type="email" wire:model="settings.company_email" class="sh-input" @disabled(! $canManage)>@error('settings.company_email') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror</div>
                <div><label for="st-addr" class="sh-label">Company address</label><textarea id="st-addr" wire:model="settings.company_address" rows="3" class="sh-input" @disabled(! $canManage)></textarea></div>
                <div><label for="st-tax" class="sh-label">Tax ID (optional)</label><input id="st-tax" type="text" wire:model="settings.tax_id" class="sh-input" @disabled(! $canManage)></div>
                <div><label for="st-pemail" class="sh-label">Payoneer account email</label><input id="st-pemail" type="email" wire:model="settings.payoneer_email" class="sh-input" @disabled(! $canManage)>@error('settings.payoneer_email') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror</div>
                <div>
                    <label for="st-plink" class="sh-label">Default Payoneer payment link</label>
                    <input id="st-plink" type="url" wire:model="settings.payoneer_payment_link" class="sh-input" placeholder="https://…" @disabled(! $canManage)>
                    @error('settings.payoneer_payment_link') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-subtle">Used for invoices without their own link. Clients pay by card or ACH on Payoneer's page.</p>
                </div>
                <div class="lg:col-span-2">
                    <label for="st-bank" class="sh-label">Bank transfer details (your Payoneer receiving accounts)</label>
                    <textarea id="st-bank" wire:model="settings.bank_details" rows="5" class="sh-input font-mono text-sm" placeholder="USD (ACH / wire)&#10;Bank: …&#10;Routing (ABA): …&#10;Account number: …&#10;Beneficiary: …" @disabled(! $canManage)></textarea>
                    <p class="mt-1 text-xs text-subtle">From Payoneer › Receive › Receiving accounts. Shown on invoices with the invoice number as reference.</p>
                </div>
                <div><label for="st-instr" class="sh-label">Payment note on invoices</label><input id="st-instr" type="text" wire:model="settings.payment_instructions" class="sh-input" @disabled(! $canManage)></div>
                <div><label for="st-due" class="sh-label">Days to pay</label><input id="st-due" type="number" min="0" max="60" wire:model="settings.due_days" class="sh-input" @disabled(! $canManage)>@error('settings.due_days') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror</div>
                @if ($canManage)<div class="lg:col-span-2 flex justify-end"><x-ui.button type="submit">Save settings</x-ui.button></div>@endif
            </form>
        </x-ui.card>
    @endif
</div>
