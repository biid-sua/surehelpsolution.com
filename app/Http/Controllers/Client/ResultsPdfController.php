<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\Metrics\ResultsPdf;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Download a month's results report as a PDF.
 */
class ResultsPdfController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current, ResultsPdf $pdf): Response
    {
        $organization = $current->get();
        abort_if($organization === null, 403);
        $month = $request->query('month');
        $month = is_string($month) ? $month : null;

        return response($pdf->render($organization, $month), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($organization, $month).'"',
        ]);
    }
}
