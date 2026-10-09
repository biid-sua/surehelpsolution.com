<?php

namespace App\Http\Controllers;

use App\Mail\ContactFormMail;
use App\Models\ContactSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The public contact form (home and Contact pages). Visitors return to the form they used, so
 * errors and the thank-you are in view. A hidden field catches bots; the route is rate-limited.
 */
class ContactController extends Controller
{
    private const SUCCESS = 'Thank you! Your message has been sent. We\'ll get back to you within one business day.';

    public function store(Request $request): RedirectResponse
    {
        $back = $this->formUrl();

        // Filled only by bots: answer as if it worked, keep nothing.
        if ($request->filled('website')) {
            return redirect()->to($back)->with('contact_success', self::SUCCESS);
        }

        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:30'],
                'company' => ['nullable', 'string', 'max:120'],
                'inquiry_type' => ['required', Rule::in(array_keys(ContactSubmission::INQUIRY_TYPES))],
                'message' => ['required', 'string', 'min:10', 'max:5000'],
                'privacy' => ['accepted'],
                'sms_consent' => ['nullable', 'boolean'],
            ], [
                'privacy.accepted' => 'Please confirm you agree to our Privacy Policy.',
                'message.min' => 'Please tell us a little more (at least 10 characters).',
            ]);
        } catch (ValidationException $e) {
            throw $e->redirectTo($back);
        }

        $submission = ContactSubmission::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'company' => $validated['company'] ?? null,
            'inquiry_type' => $validated['inquiry_type'],
            'message' => $validated['message'],
            // Only what the visitor ticked: text-message consent is optional and never a condition (TCPA, A2P 10DLC).
            'sms_consent' => $request->boolean('sms_consent'),
            'ip_address' => $request->ip(),
        ]);

        $recipient = config('mail.contact_to', config('company.email'));

        try {
            Mail::to($recipient)->send(new ContactFormMail($submission));
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect()->to($back)->with('contact_success', self::SUCCESS);
    }

    /** The page the form was sent from (our own site only), at the form. */
    private function formUrl(): string
    {
        $previous = url()->previous();
        $ours = str_starts_with($previous, url('/'));
        $path = $ours ? strtok($previous, '#') : route('site.contact');

        return $path.'#contact-form';
    }
}
