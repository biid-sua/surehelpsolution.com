{{-- Printable invoice in the browser: the PDF layout plus a small toolbar that doesn't print. --}}
@php($toolbar = true)
<div style="font-family: Helvetica, Arial, sans-serif; background:#111827; color:#fff; padding:10px 16px; display:flex; gap:16px; align-items:center;" class="no-print">
    <a href="{{ route('app.billing') }}" style="color:#c7d2fe; text-decoration:none">← Billing</a>
    <span style="flex:1"></span>
    <a href="{{ route('app.billing.invoice.pdf', $invoice->ulid) }}" style="color:#fff; background:#4f46e5; padding:6px 12px; border-radius:6px; text-decoration:none">Download PDF</a>
    <a href="#" id="print-invoice" style="color:#fff; text-decoration:none">Print</a>
</div>
<script nonce="{{ Vite::cspNonce() }}">document.getElementById('print-invoice').addEventListener('click', (e) => { e.preventDefault(); window.print(); });</script>
<style>@media print { .no-print { display: none !important; } }</style>
@include('billing.invoice', ['pdf' => false])
