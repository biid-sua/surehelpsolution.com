<?php

namespace App\Jobs;

use App\Models\DataExport;
use App\Notifications\DataExportReady;
use App\Services\Privacy\DataExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Builds a business's data export in the background and tells the person who asked.
 */
class BuildDataExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public readonly DataExport $export) {}

    public function handle(DataExporter $exporter): void
    {
        if ($this->export->status !== DataExport::PENDING) {
            return;
        }

        $exporter->build($this->export);

        $this->export->requester?->notify(new DataExportReady($this->export));
    }

    public function failed(?\Throwable $e): void
    {
        $this->export->forceFill(['status' => DataExport::FAILED])->save();
    }
}
