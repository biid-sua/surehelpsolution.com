<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingUnavailable;
use App\Models\JobDocument;
use App\Models\Organization;
use App\Services\Business\Branding;
use App\Services\Documents\JobDocuments;
use App\Services\Payments\CustomerPayments;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The page a customer opens from an estimate or invoice link (D115), in the business's look: the
 * lines and total, then accept or decline an estimate, or pay an invoice by card.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly JobDocuments $documents) {}

    public function show(Request $request, string $token, Branding $branding, CustomerPayments $payments): View
    {
        $document = $this->find($token);
        $this->documents->viewed($document);
        $organization = Organization::query()->findOrFail($document->organization_id);

        return view('documents.show', [
            'document' => $document,
            'brand' => $branding->for($organization),
            'canPay' => ! $document->isEstimate() && $document->status === JobDocument::SENT && $payments->ready($organization),
            'returning' => $request->boolean('done'),
            'error' => session('document_error'),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $document = $this->find($token);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']], ['name.required' => __('Type your name to accept.')]);
        try {
            $this->documents->accept($document, $data['name']);
        } catch (ValidationException $e) {
            return back()->with('document_error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('documents.show', $token);
    }

    public function decline(Request $request, string $token): RedirectResponse
    {
        $document = $this->find($token);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        try {
            $this->documents->decline($document, $data['reason'] ?? null);
        } catch (ValidationException $e) {
            return back()->with('document_error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('documents.show', $token);
    }

    public function pay(string $token): RedirectResponse
    {
        $document = $this->find($token);
        try {
            $url = $this->documents->checkoutUrl($document);
        } catch (BillingUnavailable $e) {
            return redirect()->route('documents.show', $token)->with('document_error', $e->getMessage());
        }

        return $url ? redirect()->away($url) : redirect()->route('documents.show', $token);
    }

    private function find(string $token): JobDocument
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);
        $document = JobDocument::withoutGlobalScopes()->with('customer')->where('token', $token)->firstOrFail();
        abort_if($document->status === JobDocument::DRAFT, 404);   // not sent yet

        return $document;
    }
}
