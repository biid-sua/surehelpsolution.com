<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\InvoicePdf;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * A business's invoice: printable page and PDF download (spec §29). Only its own invoices.
 */
class InvoiceController extends Controller
{
    public function show(CurrentOrganization $current, InvoicePdf $pdf, string $invoice): View
    {
        return view('billing.invoice-page', $pdf->data($this->find($current, $invoice)));
    }

    public function pdf(CurrentOrganization $current, InvoicePdf $pdf, string $invoice): Response
    {
        $model = $this->find($current, $invoice);

        return response($pdf->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($model).'"',
        ]);
    }

    private function find(CurrentOrganization $current, string $ulid): Invoice
    {
        return Invoice::query()->forOrganization($current->get())->where('ulid', $ulid)->firstOrFail();
    }
}
