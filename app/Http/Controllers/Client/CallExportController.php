<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Queries\CallLogFilters;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export of the current organization's calls, honouring the same filters as the calls table (spec §91).
 * Streams in chunks, so large histories don't load into memory.
 */
class CallExportController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current): StreamedResponse
    {
        $organization = $current->get();
        abort_if($organization === null, 403);

        $filters = CallLogFilters::fromArray($request->only(['search', 'view', 'from', 'to']));
        $timezone = $organization->timezone ?: config('app.timezone');

        $columns = [
            'Call ID', 'Logged at ('.$timezone.')', 'Caller', 'Phone', 'Email', 'Reason', 'Outcome', 'Status',
            'Service requested', 'Service date', 'Service window', 'Service location', 'Agent', 'Notes',
        ];

        $filename = str($organization->name)->slug().'-calls-'.now($timezone)->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($filters, $organization, $columns, $timezone) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows accents correctly
            fputcsv($out, $columns);

            $filters->apply($organization)->chunk(500, function ($calls) use ($out, $timezone) {
                foreach ($calls as $call) {
                    /** @var CallLog $call */
                    fputcsv($out, array_map([self::class, 'safe'], [
                        $call->call_id,
                        $call->created_at->setTimezone($timezone)->format('Y-m-d H:i'),
                        $call->caller_name,
                        $call->caller_phone,
                        $call->caller_email,
                        str($call->reason_for_call)->headline(),
                        str($call->call_outcome)->headline(),
                        $call->statusLabel(),
                        $call->service_request ? 'Yes' : 'No',
                        $call->service_date?->format('Y-m-d'),
                        $call->service_window,
                        $call->service_location,
                        $call->agent_name,
                        $call->notes,
                    ]));
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Neutralise spreadsheet formulas in caller-supplied text (CSV injection).
     */
    public static function safe(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
