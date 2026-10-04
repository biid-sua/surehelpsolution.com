<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Client\CallExportController as ClientCallExport;
use App\Http\Controllers\Controller;
use App\Models\CallLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV of the calls the signed-in agent logged, across every business they answer for.
 */
class CallExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();
        $columns = ['Call ID', 'Logged at (UTC)', 'Business', 'Caller', 'Phone', 'Email', 'Reason', 'Outcome', 'Status', 'Service date', 'Service window', 'Service location', 'Notes'];

        return response()->streamDownload(function () use ($user, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows accents correctly
            fputcsv($out, $columns);

            CallLog::withoutGlobalScopes()->where('user_id', $user->id)->with('organization:id,name')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->chunk(500, function ($calls) use ($out) {
                    foreach ($calls as $call) {
                        /** @var CallLog $call */
                        fputcsv($out, array_map([ClientCallExport::class, 'safe'], [
                            $call->call_id,
                            $call->created_at->utc()->format('Y-m-d H:i'),
                            $call->organization?->name,
                            $call->caller_name,
                            $call->caller_phone,
                            $call->caller_email,
                            str($call->reason_for_call)->headline(),
                            str($call->call_outcome)->headline(),
                            $call->statusLabel(),
                            $call->service_date?->format('Y-m-d'),
                            $call->service_window,
                            $call->service_location,
                            $call->notes,
                        ]));
                    }
                });

            fclose($out);
        }, 'my-calls-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
