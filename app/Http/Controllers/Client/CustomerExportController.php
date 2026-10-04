<?php

namespace App\Http\Controllers\Client;

use App\Enums\CustomerStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export of the current business's customers with the list's filters (spec §91).
 */
class CustomerExportController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current): StreamedResponse
    {
        $organization = $current->get();
        abort_if($organization === null, 403);
        $timezone = $organization->timezoneOrDefault();

        $query = Customer::query()->forOrganization($organization)
            ->with('tags:id,name')
            ->search((string) $request->query('search', ''))
            ->when(CustomerStatus::tryFrom((string) $request->query('status')), fn (Builder $q, CustomerStatus $s) => $q->where('status', $s))
            ->when($request->filled('tag'), fn (Builder $q) => $q->whereHas('tags', fn (Builder $t) => $t->whereKey((int) $request->query('tag'))))
            ->orderBy('id');

        $columns = ['First name', 'Last name', 'Company', 'Phone', 'Email', 'Address', 'Status', 'Source', 'Tags', 'OK to text', 'OK to email', 'Last activity ('.$timezone.')', 'Created'];

        return response()->streamDownload(function () use ($query, $columns, $timezone) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);

            $query->chunk(500, function ($customers) use ($out, $timezone) {
                foreach ($customers as $c) {
                    /** @var Customer $c */
                    fputcsv($out, array_map([CallExportController::class, 'safe'], [
                        $c->first_name, $c->last_name, $c->company, $c->displayPhone(), $c->email, $c->singleLineAddress(),
                        $c->status->label(), $c->source, $c->tags->pluck('name')->implode(', '),
                        $c->sms_consent ? 'Yes' : 'No', $c->email_consent ? 'Yes' : 'No',
                        $c->last_activity_at?->setTimezone($timezone)->format('Y-m-d H:i'),
                        $c->created_at?->setTimezone($timezone)->format('Y-m-d'),
                    ]));
                }
            });

            fclose($out);
        }, str($organization->name)->slug().'-customers-'.now($timezone)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
