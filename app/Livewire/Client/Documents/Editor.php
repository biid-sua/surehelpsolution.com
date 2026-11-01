<?php

namespace App\Livewire\Client\Documents;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Customer;
use App\Models\JobDocument;
use App\Services\Documents\JobDocuments;
use App\Services\Payments\CustomerPayments;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Writing, sending and following one estimate or invoice (D115). Staff with payments.request write
 * and send them; everyone with payments.view can look.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Estimate or invoice')]
class Editor extends Component
{
    use ScopedToOrganization;

    public ?string $ulid = null;

    public string $type = JobDocument::ESTIMATE;

    public string $customer = '';   // ulid

    public string $customerSearch = '';

    public string $title = '';

    /** @var list<array{description: string, quantity: string, price: string}> */
    public array $items = [];

    public string $notes = '';

    public string $taxRate = '';

    public string $date = '';   // valid until (estimate) or due on (invoice)

    /** @var list<string> */
    public array $channels = ['sms', 'email'];

    public string $paidHow = 'cash';

    public function mount(?JobDocument $document = null): void
    {
        $organization = $this->organization();
        $this->authorize('payments.view', $organization);
        $zone = $organization->timezoneOrDefault();
        if ($document?->exists) {
            $this->ulid = $document->ulid;
            $this->type = $document->type;
            $this->customer = (string) $document->customer?->ulid;
            $this->title = $document->title;
            $this->items = array_map(fn (array $i) => ['description' => $i['description'], 'quantity' => rtrim(rtrim(number_format((float) $i['quantity'], 2, '.', ''), '0'), '.'),
                'price' => Money::toInput((int) $i['unit_cents'])], $document->items);
            $this->notes = (string) $document->notes;
            $this->taxRate = $document->tax_rate_bp ? rtrim(rtrim(number_format($document->tax_rate_bp / 100, 2, '.', ''), '0'), '.') : '';
            $this->date = (string) ($document->isEstimate() ? $document->valid_until?->toDateString() : $document->due_on?->toDateString());

            return;
        }
        $this->authorize('payments.request', $organization);
        $this->type = request()->query('type') === JobDocument::INVOICE ? JobDocument::INVOICE : JobDocument::ESTIMATE;
        $this->customer = (string) (Customer::query()->forOrganization($organization)->where('ulid', (string) request()->query('customer'))->value('ulid') ?? '');
        $this->items = [['description' => '', 'quantity' => '1', 'price' => '']];
        $this->date = now($zone)->addDays($this->type === JobDocument::ESTIMATE ? 30 : 14)->toDateString();
    }

    public function addItem(): void
    {
        if (count($this->items) < JobDocuments::MAX_ITEMS) {
            $this->items[] = ['description' => '', 'quantity' => '1', 'price' => ''];
        }
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items) ?: [['description' => '', 'quantity' => '1', 'price' => '']];
    }

    public function pickCustomer(string $ulid): void
    {
        $this->customer = (string) (Customer::query()->forOrganization($this->organization())->where('ulid', $ulid)->value('ulid') ?? '');
        $this->customerSearch = '';
    }

    public function save(JobDocuments $documents): void
    {
        $organization = $this->organization();
        $this->authorize('payments.request', $organization);
        $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'items' => ['required', 'array', 'min:1', 'max:'.JobDocuments::MAX_ITEMS],
            'items.*.description' => ['nullable', 'string', 'max:300'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'taxRate' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'date' => ['nullable', 'date'],
        ], ['title.required' => 'Give it a short title, like "Water heater replacement".'], ['taxRate' => 'tax rate']);
        $lines = [];
        foreach ($this->items as $i => $item) {
            if (trim((string) $item['description']) === '') {
                continue;
            }
            try {
                $cents = filled($item['price']) ? Money::parse((string) $item['price']) : 0;
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(["items.$i.price" => 'Enter an amount like 250 or 250.00.']);
            }
            $lines[] = ['description' => $item['description'], 'quantity' => (float) ($item['quantity'] ?: 1), 'unit_cents' => $cents];
        }
        $data = ['title' => $this->title, 'items' => $lines, 'notes' => $this->notes, 'tax_rate_bp' => (int) round((float) $this->taxRate * 100),
            $this->type === JobDocument::ESTIMATE ? 'valid_until' : 'due_on' => $this->date ?: null];

        if ($this->ulid) {
            $documents->update($this->document(), $data, auth()->user());
            $this->dispatch('toast', type: 'success', message: 'Saved.');

            return;
        }
        $customer = Customer::query()->forOrganization($organization)->where('ulid', $this->customer)->first();
        if (! $customer) {
            throw ValidationException::withMessages(['customer' => 'Choose the customer.']);
        }
        $document = $documents->create($organization, $this->type, $customer, $data, auth()->user());
        session()->flash('status', ucfirst($this->type).' '.$document->number.' saved. Send it when you\'re ready.');
        $this->redirectRoute('app.documents.edit', $document, navigate: true);
    }

    public function send(JobDocuments $documents): void
    {
        $this->authorize('payments.request', $this->organization());
        $this->validate(['channels' => ['required', 'array', 'min:1'], 'channels.*' => ['in:sms,email']], ['channels.required' => 'Choose text, email or both.']);
        $sent = $documents->send($this->document(), $this->channels, auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Sent by '.collect($sent)->map(fn ($c) => $c === 'sms' ? 'text' : 'email')->implode(' and ').'.');
    }

    public function makeInvoice(JobDocuments $documents): void
    {
        $this->authorize('payments.request', $this->organization());
        $invoice = $documents->invoiceFrom($this->document(), auth()->user());
        session()->flash('status', 'Invoice '.$invoice->number.' made from the estimate. Check it and send it.');
        $this->redirectRoute('app.documents.edit', $invoice, navigate: true);
    }

    public function markPaid(JobDocuments $documents): void
    {
        $this->authorize('payments.request', $this->organization());
        $documents->markPaid($this->document(), $this->paidHow, auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Marked as paid.');
    }

    public function void(JobDocuments $documents): void
    {
        $this->authorize('payments.request', $this->organization());
        $documents->void($this->document(), auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Voided. The customer\'s link no longer works for accepting or paying.');
    }

    private function document(): JobDocument
    {
        return JobDocument::query()->forOrganization($this->organization())->where('ulid', (string) $this->ulid)->first() ?? abort(404);
    }

    public function render(CustomerPayments $payments): View
    {
        $organization = $this->organization();
        $document = $this->ulid ? $this->document()->load(['customer', 'estimate', 'paymentRequest']) : null;
        $subtotal = 0;
        foreach ($this->items as $item) {
            $cents = rescue(fn () => filled($item['price']) ? Money::parse((string) $item['price']) : 0, 0, report: false);
            $subtotal += (int) round((float) ($item['quantity'] ?: 1) * $cents);
        }
        $tax = (int) round($subtotal * (float) $this->taxRate / 100);

        return view('livewire.client.documents.editor', [
            'document' => $document,
            'editable' => ! $document || in_array($document->status, [JobDocument::DRAFT, JobDocument::SENT], true),
            'canWrite' => auth()->user()->can('payments.request', $organization),
            'chosen' => $this->customer ? Customer::query()->forOrganization($organization)->where('ulid', $this->customer)->first() : null,
            'matches' => ! $document && strlen(trim($this->customerSearch)) >= 2
                ? Customer::query()->forOrganization($organization)->search(trim($this->customerSearch))->limit(6)->get() : collect(),
            'subtotal' => $subtotal, 'tax' => $tax,
            'paymentsReady' => $payments->ready($organization),
            'timezone' => $organization->timezoneOrDefault(),
            'invoice' => $document?->isEstimate() ? JobDocument::query()->forOrganization($organization)->where('estimate_id', $document->id)->first() : null,
        ])->title(($document?->label() ?? 'New '.$this->type));
    }
}
