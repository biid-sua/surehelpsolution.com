{{-- Invoice document: used for the PDF (dompdf: simple CSS only) and the printable page. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #111827; font-size: 12px; margin: {{ ($pdf ?? false) ? '0' : '32px auto' }}; max-width: 760px; }
        h1 { font-size: 26px; margin: 0; letter-spacing: 1px; }
        .muted { color: #6b7280; }
        .row { width: 100%; }
        .col { display: inline-block; vertical-align: top; width: 49%; }
        .right { text-align: right; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 24px; }
        table.items th { text-align: left; font-size: 11px; text-transform: uppercase; color: #6b7280; border-bottom: 2px solid #111827; padding: 8px 4px; }
        table.items td { border-bottom: 1px solid #e5e7eb; padding: 10px 4px; }
        .totals { width: 280px; margin-left: auto; margin-top: 12px; }
        .totals td { padding: 4px; }
        .totals .grand td { border-top: 2px solid #111827; font-weight: bold; font-size: 14px; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .paid { background: #d1fae5; color: #065f46; } .open { background: #fef3c7; color: #92400e; } .void { background: #e5e7eb; color: #374151; }
        .box { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; margin-top: 12px; }
        .pre { white-space: pre-line; }
        a { color: #4f46e5; }
    </style>
</head>
<body>
    <div class="row">
        <div class="col">
            <h1>INVOICE</h1>
            <p class="muted" style="margin-top:6px">{{ $invoice->number }}</p>
            <span class="badge {{ $invoice->status->value }}">{{ strtoupper($invoice->status->label()) }}</span>
        </div>
        <div class="col right">
            <strong>{{ $company['company_name'] }}</strong>
            @if ($company['company_address'])<div class="pre muted">{{ $company['company_address'] }}</div>@endif
            @if ($company['company_email'])<div class="muted">{{ $company['company_email'] }}</div>@endif
            @if ($company['tax_id'])<div class="muted">Tax ID: {{ $company['tax_id'] }}</div>@endif
        </div>
    </div>

    <div class="row" style="margin-top:28px">
        <div class="col">
            <div class="muted" style="font-size:11px;text-transform:uppercase">Billed to</div>
            <strong>{{ $invoice->billing_details['name'] ?? $invoice->organization?->name }}</strong>
            @isset($invoice->billing_details['address'])<div class="pre">{{ $invoice->billing_details['address'] }}</div>@endisset
            @isset($invoice->billing_details['email'])<div>{{ $invoice->billing_details['email'] }}</div>@endisset
        </div>
        <div class="col right">
            <div><span class="muted">Issued:</span> {{ $invoice->issued_at->format('M j, Y') }}</div>
            <div><span class="muted">Due:</span> {{ $invoice->due_at->format('M j, Y') }}</div>
            @if ($invoice->periodLabel())<div><span class="muted">Service period:</span> {{ $invoice->periodLabel() }}</div>@endif
            @if ($invoice->paid_at)<div><span class="muted">Paid:</span> {{ $invoice->paid_at->format('M j, Y') }}</div>@endif
        </div>
    </div>

    <table class="items">
        <thead><tr><th>Description</th><th class="right">Qty</th><th class="right">Price</th><th class="right">Amount</th></tr></thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr><td>{{ $item->description }}</td><td class="right">{{ $item->quantity }}</td><td class="right">{{ $invoice->money($item->unit_cents) }}</td><td class="right">{{ $invoice->money($item->amount_cents) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="right">{{ $invoice->money($invoice->subtotal_cents) }}</td></tr>
        <tr class="grand"><td>Total ({{ $invoice->currency }})</td><td class="right">{{ $invoice->money($invoice->total_cents) }}</td></tr>
        @if ($invoice->amount_paid_cents > 0)
            <tr><td>Paid</td><td class="right">−{{ $invoice->money($invoice->amount_paid_cents) }}</td></tr>
            <tr><td><strong>Balance due</strong></td><td class="right"><strong>{{ $invoice->money($invoice->balanceCents()) }}</strong></td></tr>
        @endif
    </table>

    @if ($options !== [])
        <h3 style="margin-top:28px">How to pay</h3>
        @foreach ($options as $option)
            <div class="box">
                <strong>{{ $option->title }}</strong>
                <div class="muted">{{ $option->description }}</div>
                @if ($option->url)<div style="margin-top:6px"><a href="{{ $option->url }}">{{ $option->url }}</a></div>@endif
                @if ($option->details)<div class="pre" style="margin-top:6px">{{ $option->details }}</div>@endif
            </div>
        @endforeach
        @if ($company['payment_instructions'])<p class="muted" style="margin-top:12px">{{ $company['payment_instructions'] }}</p>@endif
    @endif

    @if ($invoice->payments->isNotEmpty())
        <h3 style="margin-top:28px">Payments received</h3>
        @foreach ($invoice->payments as $payment)
            <div>{{ $payment->received_at->format('M j, Y') }} · {{ $payment->method->label() }} · {{ $payment->amountLabel() }}@if ($payment->reference) · ref {{ $payment->reference }}@endif</div>
        @endforeach
    @endif

    <p class="muted" style="margin-top:36px;font-size:10px">Thank you for your business.</p>
</body>
</html>
