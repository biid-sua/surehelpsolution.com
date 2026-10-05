<?php

namespace App\Console\Commands;

use App\Models\DataExport;
use App\Models\Organization;
use App\Services\Privacy\AccountClosure;
use App\Services\Privacy\Retention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Daily data housekeeping (spec §56, task.md CMP-05, CMP-07): each business's retention period,
 * expired data exports, and accounts whose closure date has come.
 */
class RunPrivacyTasks extends Command
{
    protected $signature = 'privacy:run';

    protected $description = 'Apply retention periods, delete expired exports and close accounts that are due';

    public function handle(Retention $retention, AccountClosure $closure): int
    {
        $closed = 0;
        Organization::query()->whereNotNull('closes_at')->whereNull('closed_at')->where('closes_at', '<=', now())
            ->each(function (Organization $organization) use ($closure, &$closed) {
                $closure->close($organization);
                $closed++;
            });

        $deleted = collect();
        Organization::query()->whereNull('closed_at')->whereNotNull('retention_months')
            ->each(function (Organization $organization) use ($retention, &$deleted) {
                foreach ($retention->apply($organization) as $kind => $count) {
                    $deleted[$kind] = ($deleted[$kind] ?? 0) + $count;
                }
            });

        $expired = 0;
        DataExport::query()->where(fn ($q) => $q->where('expires_at', '<', now())->orWhere(fn ($q) => $q->where('status', DataExport::FAILED)->where('created_at', '<', now()->subDay())))
            ->whereNot('status', DataExport::EXPIRED)
            ->each(function (DataExport $export) use (&$expired) {
                if ($export->path) {
                    Storage::disk('local')->delete($export->path);
                }
                $export->forceFill(['status' => DataExport::EXPIRED, 'path' => null])->save();
                $expired++;
            });

        $this->info("Closed {$closed} ".str('account')->plural($closed).'; expired '.$expired.' '.str('export')->plural($expired).'; retention deleted: '
            .($deleted->filter()->isEmpty() ? 'nothing' : $deleted->filter()->map(fn ($n, $k) => "$n $k")->implode(', ')).'.');

        return self::SUCCESS;
    }
}
