<?php

namespace App\Http\Controllers;

use App\Mail\ContactFormMail;
use App\Models\ContactSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class ContactController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'company' => ['nullable', 'string', 'max:120'],
            'inquiry_type' => ['required', Rule::in(array_keys(ContactSubmission::INQUIRY_TYPES))],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'privacy' => ['accepted'],
        ], [
            'privacy.accepted' => 'Please confirm you agree to our Privacy Policy and communication preferences.',
        ]);

        $submission = ContactSubmission::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'company' => $validated['company'] ?? null,
            'inquiry_type' => $validated['inquiry_type'],
            'message' => $validated['message'],
            'sms_consent' => true,
            'ip_address' => $request->ip(),
        ]);

        $recipient = config('mail.contact_to', config('company.email'));

        try {
            Mail::to($recipient)->send(new ContactFormMail($submission));
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect()
            ->to(route('home').'#contact')
            ->with('contact_success', 'Thank you! Your message has been sent. We\'ll get back to you within one business day.');
    }
}
