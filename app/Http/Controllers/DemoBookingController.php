<?php

namespace App\Http\Controllers;

use App\Models\ContactSubmission;
use App\Services\Sales\DemoFollowUp;
use App\Services\Sales\DemoSlots;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The visitor's private page for a demo call (D96), reached from the invitation, reminders and the
 * "sorry we missed you" email: see the time and the Teams link, move it, or cancel it. Knowing the
 * 40-character token is the only access, like a calendar invitation link.
 */
class DemoBookingController extends Controller
{
    public function show(string $token, DemoSlots $slots, DemoFollowUp $demo): View
    {
        $enquiry = $this->find($token);
        $step = $this->step($enquiry);

        return view('demo.show', [
            'enquiry' => $enquiry,
            'step' => $step,
            'label' => $enquiry->demo_at ? $slots->label(CarbonImmutable::parse($enquiry->demo_at)) : null,
            'location' => $demo->where(),
            'meetingUrl' => $demo->meetingUrl(),
            'days' => in_array($step, ['upcoming', 'rebook'], true) ? $slots->days(null, 5, 8) : [],
            'timezone' => CarbonImmutable::now($slots->timezone())->format('T'),
        ]);
    }

    public function reschedule(Request $request, string $token, DemoSlots $slots, DemoFollowUp $demo): RedirectResponse
    {
        $enquiry = $this->find($token);
        abort_unless(in_array($this->step($enquiry), ['upcoming', 'rebook'], true), 404);
        $at = $slots->check((string) $request->input('starts_at'));
        if (! $at || ! $demo->reschedule($enquiry, $at)) {
            return redirect()->route('demo.show', $token)->with('demo_error', 'That time was just taken. Please choose another.');
        }

        return redirect()->route('demo.show', $token)->with('demo_done', 'moved');
    }

    public function cancel(string $token, DemoFollowUp $demo): RedirectResponse
    {
        $enquiry = $this->find($token);
        abort_unless($this->step($enquiry) === 'upcoming', 404);
        $demo->cancel($enquiry);

        return redirect()->route('demo.show', $token)->with('demo_done', 'cancelled');
    }

    private function find(string $token): ContactSubmission
    {
        abort_unless(strlen($token) === 40, 404);

        return ContactSubmission::query()->where('demo_token', $token)->whereNotNull('demo_status')->firstOrFail();
    }

    /** upcoming: booked, still ahead · rebook: cancelled or missed · past: it happened. */
    private function step(ContactSubmission $enquiry): string
    {
        return match (true) {
            $enquiry->demo_status === 'booked' && $enquiry->demo_at?->isFuture() => 'upcoming',
            in_array($enquiry->demo_status, ['cancelled', 'no_show'], true) => 'rebook',
            default => 'past',
        };
    }
}
