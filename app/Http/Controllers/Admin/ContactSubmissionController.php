<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactSubmission;
use Illuminate\Support\Facades\Auth;

class ContactSubmissionController extends Controller
{
    public static function inquiryTypeLabels(): array
    {
        return [
            'general' => 'General Inquiry',
            'sales' => 'Sales',
            'demo' => 'Demo Request',
            'support' => 'Support',
            'enterprise' => 'Enterprise',
        ];
    }

    public function index()
    {
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Unauthorized access');
        }

        $submissions = ContactSubmission::orderByDesc('created_at')->paginate(20);
        $inquiryLabels = self::inquiryTypeLabels();

        return view('admin.contact-submissions.index', compact('submissions', 'inquiryLabels'));
    }

    public function show(ContactSubmission $contactSubmission)
    {
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Unauthorized access');
        }

        $inquiryLabels = self::inquiryTypeLabels();

        return view('admin.contact-submissions.show', [
            'submission' => $contactSubmission,
            'inquiryLabels' => $inquiryLabels,
        ]);
    }
}
