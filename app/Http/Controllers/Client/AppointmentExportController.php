<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Livewire\Client\Appointments\Index;
use App\Models\Appointment;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export of the current business's appointments, for the tab the list shows (spec §91).
 */
class AppointmentExportController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current): StreamedResponse
    {
        $organization = $current->get();
        abort_if($organization === null, 403);
        $timezone = $organization->timezoneOrDefault();
        $view = array_key_exists((string) $request->query('view'), Index::VIEWS) ? (string) $request->query('view') : 'upcoming';

        $query = Appointment::query()->forOrganization($organization)
            ->with(['customer:id,first_name,last_name,company,phone,phone_e164,email', 'service:id,name', 'location:id,name', 'bookedBy:id,name', 'call:id,call_id'])
            ->tap(fn ($q) => Index::applyView($q, $view, $timezone, [
                'search' => (string) $request->query('search', ''), 'from' => (string) $request->query('from', ''),
                'to' => (string) $request->query('to', ''), 'service' => (string) $request->query('service', ''),
            ]));

        $columns = ['Date', 'Start ('.$timezone.')', 'End', 'Title', 'Service', 'Customer', 'Phone', 'Email', 'Address', 'Location',
            'Status', 'Booked by', 'Source', 'Call ID', 'Notes', 'Cancellation reason', 'Created'];

        return response()->streamDownload(function () use ($query, $columns, $timezone) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);

            // A cursor keeps the list's order without loading everything at once.
            foreach ($query->cursor() as $a) {
                /** @var Appointment $a */
                $start = $a->starts_at->setTimezone($timezone);
                fputcsv($out, array_map([CallExportController::class, 'safe'], [
                    $start->format('Y-m-d'), $start->format('H:i'), $a->ends_at->setTimezone($timezone)->format('H:i'),
                    $a->title, $a->service?->name, $a->customer?->fullName(), $a->customer?->displayPhone(), $a->customer?->email,
                    $a->address, $a->location?->name, $a->status->label(), $a->bookedBy?->name, $a->source, $a->call?->call_id,
                    $a->notes, $a->cancellation_reason, $a->created_at?->setTimezone($timezone)->format('Y-m-d H:i'),
                ]));
            }

            fclose($out);
        }, str($organization->name)->slug().'-appointments-'.$view.'-'.now($timezone)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
