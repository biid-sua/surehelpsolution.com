<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SmsOptOut;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The business's consent records as a spreadsheet (D117): who agreed to texts and email, when and
 * how, and who opted out. What a carrier or a lawyer asks for under the TCPA and A2P 10DLC rules.
 */
class ConsentExportController extends Controller
{
    public function __invoke(CurrentOrganization $current, Audit $audit): StreamedResponse
    {
        $organization = $current->get();
        $timezone = $organization->timezoneOrDefault();
        $optOuts = SmsOptOut::query()->forOrganization($organization)->pluck('opted_out_at', 'phone');
        $audit->record('compliance.consent_exported', null, organization: $organization, label: 'Consent records');
        $when = fn ($date) => $date ? $date->copy()->timezone($timezone)->format('Y-m-d H:i') : '';

        return response()->streamDownload(function () use ($organization, $optOuts, $when) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Customer', 'Phone', 'Email', 'Texts allowed', 'Texts allowed since', 'How they agreed', 'Texted STOP on', 'Email allowed', 'Unsubscribed from reminders and offers on']);
            Customer::query()->forOrganization($organization)->orderBy('id')->chunk(500, function ($customers) use ($out, $optOuts, $when) {
                foreach ($customers as $customer) {
                    $stop = $customer->phone_e164 ? ($optOuts[$customer->phone_e164] ?? null) : null;
                    fputcsv($out, [
                        $customer->fullName(), $customer->phone_e164, $customer->email, $customer->sms_consent ? 'yes' : 'no', $when($customer->sms_consent_at),
                        $customer->consent_source, $stop ? $when(Carbon::parse($stop)) : '', $customer->email_consent ? 'yes' : 'no',
                        $when($customer->marketing_opt_out_at),
                    ]);
                }
            });
            fclose($out);
        }, 'consent-records-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
