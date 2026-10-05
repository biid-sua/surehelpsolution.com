<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\DataExport;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a finished data export. Only for the business it belongs to, only while it's valid,
 * and every download is audited (spec §62).
 */
class DataExportController extends Controller
{
    public function __invoke(DataExport $export, CurrentOrganization $current, Audit $audit): StreamedResponse
    {
        $organization = $current->get();
        abort_unless($organization && $export->organization_id === $organization->id, 404);
        abort_unless($export->isDownloadable() && Storage::disk('local')->exists((string) $export->path), 410, 'This export has expired. Make a new one.');

        $audit->record('data_export.downloaded', $export, organization: $organization);

        return Storage::disk('local')->download((string) $export->path,
            str($organization->name)->slug().'-data-'.$export->completed_at?->format('Y-m-d').'.zip');
    }
}
