<?php

namespace App\Services\Privacy;

use App\Http\Controllers\Client\CallExportController;
use App\Models\Appointment;
use App\Models\BusinessHoliday;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\BusinessRule;
use App\Models\BusinessService;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\DataExport;
use App\Models\Escalation;
use App\Models\Invoice;
use App\Models\KnowledgeItem;
use App\Models\Message;
use App\Models\SocialPost;
use App\Models\Task;
use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Everything a business has with us (including inbox messages and social posts), as one ZIP of CSV files plus its settings as JSON (spec §91,
 * task.md CMP-07). Built in the background; the link works for 7 days.
 */
class DataExporter
{
    public const KEEP_DAYS = 7;

    public function build(DataExport $export): void
    {
        $organization = $export->organization;
        $timezone = $organization->timezoneOrDefault();
        $time = fn ($value) => $value?->copy()->setTimezone($timezone)->format('Y-m-d H:i');
        $dir = storage_path('app/private/exports/tmp-'.$export->ulid);
        @mkdir($dir, 0700, true);

        try {
            $files = [
                'customers.csv' => $this->csv("$dir/customers.csv", Customer::withTrashed()->forOrganization($organization)->with('tags:id,name'),
                    ['First name', 'Last name', 'Company', 'Phone', 'Email', 'Address', 'Status', 'Source', 'Tags', 'OK to text', 'OK to text since', 'OK to email', 'OK to email since', 'Notes', 'Created', 'Archived'],
                    fn (Customer $c) => [$c->first_name, $c->last_name, $c->company, $c->displayPhone(), $c->email, $c->singleLineAddress(), $c->status->label(), $c->source,
                        $c->tags->pluck('name')->implode(', '), $c->sms_consent ? 'Yes' : 'No', $time($c->sms_consent_at), $c->email_consent ? 'Yes' : 'No', $time($c->email_consent_at),
                        $c->notes, $time($c->created_at), $time($c->deleted_at)]),
                'calls.csv' => $this->csv("$dir/calls.csv", CallLog::query()->forOrganization($organization),
                    ['Call ID', 'Logged at ('.$timezone.')', 'Caller', 'Phone', 'Email', 'Reason', 'Outcome', 'Status', 'Service requested', 'Service date', 'Service window', 'Service location', 'Agent', 'Notes'],
                    fn (CallLog $c) => [$c->call_id, $time($c->created_at), $c->caller_name, $c->caller_phone, $c->caller_email, $c->reason_for_call, $c->call_outcome, $c->statusLabel(),
                        $c->service_request ? 'Yes' : 'No', $c->service_date?->toDateString(), $c->service_window, $c->service_location, $c->agent_name, $c->notes]),
                'appointments.csv' => $this->csv("$dir/appointments.csv", Appointment::query()->forOrganization($organization)->with('customer:id,first_name,last_name'),
                    ['Title', 'Starts ('.$timezone.')', 'Ends', 'Status', 'Customer', 'Address', 'Notes', 'Source', 'Cancellation reason'],
                    fn (Appointment $a) => [$a->title, $time($a->starts_at), $time($a->ends_at), $a->status->label(), $a->customer?->fullName(), $a->address, $a->notes, $a->source, $a->cancellation_reason]),
                'tasks.csv' => $this->csv("$dir/tasks.csv", Task::query()->forOrganization($organization),
                    ['Title', 'Type', 'Priority', 'Status', 'Due', 'Completed', 'Description'],
                    fn (Task $t) => [$t->title, $t->type->value, $t->priority->value, $t->status->value, $time($t->due_at), $time($t->completed_at), $t->description]),
                'escalations.csv' => $this->csv("$dir/escalations.csv", Escalation::query()->forOrganization($organization),
                    ['Raised', 'Type', 'Priority', 'Status', 'Reason', 'Details', 'Resolved', 'Resolution'],
                    fn (Escalation $e) => [$time($e->created_at), $e->type->label(), $e->priority->label(), $e->status->label(), $e->reason, $e->details, $time($e->resolved_at), $e->resolution_notes]),
                'conversations.csv' => $this->csv("$dir/conversations.csv", Conversation::withoutGlobalScopes()->where('organization_id', $organization->id)->with('customer:id,first_name,last_name'),
                    ['Conversation', 'Channel', 'Contact', 'Customer', 'Status', 'Started', 'Last message'],
                    fn (Conversation $c) => [$c->ulid, $c->channel->label(), $c->contact_name ?? $c->contact_handle, $c->customer?->fullName(), $c->status, $time($c->created_at), $time($c->last_message_at)]),
                'messages.csv' => $this->csv("$dir/messages.csv", Message::withoutGlobalScopes()->where('organization_id', $organization->id)->with('conversation:id,ulid'),
                    ['Conversation', 'Sent ('.$timezone.')', 'From', 'Internal note', 'Status', 'Message'],
                    fn (Message $m) => [$m->conversation?->ulid, $time($m->sent_at ?? $m->created_at),
                        $m->direction === Message::IN ? 'Customer' : (['ai' => 'AI assistant', 'user' => 'Team', 'system' => 'System'][$m->author_type] ?? $m->author_type),
                        $m->is_note ? 'Yes' : 'No', $m->status, $m->body]),
                'social_posts.csv' => $this->csv("$dir/social_posts.csv", SocialPost::withoutGlobalScopes()->where('organization_id', $organization->id),
                    ['Status', 'Scheduled ('.$timezone.')', 'Published', 'Text', 'Link'],
                    fn (SocialPost $p) => [$p->status->value, $time($p->scheduled_at), $time($p->published_at), $p->body, $p->link_url]),
                'invoices.csv' => $this->csv("$dir/invoices.csv", Invoice::query()->forOrganization($organization),
                    ['Number', 'Issued', 'Due', 'Status', 'Total', 'Currency'],
                    fn (Invoice $i) => [$i->number, $time($i->issued_at), $time($i->due_at), $i->status->label(), number_format($i->total_cents / 100, 2, '.', ''), $i->currency]),
            ];

            file_put_contents("$dir/business.json", json_encode([
                'business' => $organization->only(['name', 'timezone', 'currency', 'created_at']),
                'profile' => BusinessProfile::query()->forOrganization($organization)->first()?->makeHidden(['id', 'organization_id'])->toArray(),
                'hours' => BusinessHour::query()->forOrganization($organization)->get(['day_of_week', 'opens_at', 'closes_at', 'location_id'])->toArray(),
                'holidays' => BusinessHoliday::query()->forOrganization($organization)->get(['date', 'name', 'is_closed', 'opens_at', 'closes_at'])->toArray(),
                'services' => BusinessService::query()->forOrganization($organization)->get()->makeHidden(['id', 'organization_id'])->toArray(),
                'rules' => BusinessRule::query()->forOrganization($organization)->get()->makeHidden(['id', 'organization_id'])->toArray(),
                'websites' => Website::withoutGlobalScopes()->where('organization_id', $organization->id)->get(['url', 'verified_at', 'last_checked_at', 'health_score'])->toArray(),
                'knowledge' => KnowledgeItem::query()->forOrganization($organization)->get()->makeHidden(['id', 'organization_id'])->toArray(),
                'exported_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $files['business.json'] = "$dir/business.json";

            file_put_contents("$dir/README.txt", "Data export for {$organization->name}, made ".now($timezone)->format('Y-m-d H:i')." ({$timezone}).\n"
                ."CSV files open in Excel, Numbers or Google Sheets. business.json holds your profile, hours, services, call rules and answers.\n"
                ."Times are in {$timezone} unless a column says otherwise.\n");
            $files['README.txt'] = "$dir/README.txt";

            $path = 'exports/'.$organization->ulid.'/'.$export->ulid.'.zip';
            $zip = new ZipArchive;
            Storage::disk('local')->makeDirectory(dirname($path));
            if ($zip->open(Storage::disk('local')->path($path), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create the export archive.');
            }
            foreach ($files as $name => $file) {
                $zip->addFile($file, $name);
            }
            $zip->close();

            $export->forceFill([
                'status' => DataExport::READY,
                'path' => $path,
                'size_bytes' => Storage::disk('local')->size($path),
                'completed_at' => now(),
                'expires_at' => now()->addDays(self::KEEP_DAYS),
            ])->save();
        } finally {
            foreach (glob("$dir/*") ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @param  callable(TModel): array<int, mixed>  $row
     */
    private function csv(string $file, Builder $query, array $columns, callable $row): string
    {
        $out = fopen($file, 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $columns);
        $query->orderBy($query->getModel()->getQualifiedKeyName())->chunk(500, function ($models) use ($out, $row) {
            foreach ($models as $model) {
                fputcsv($out, array_map([CallExportController::class, 'safe'], $row($model)));
            }
        });
        fclose($out);

        return $file;
    }
}
