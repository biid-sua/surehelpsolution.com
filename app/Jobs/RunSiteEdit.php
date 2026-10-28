<?php

namespace App\Jobs;

use App\Models\SiteEdit;
use App\Services\Sites\SiteEditor;
use App\Support\DemoGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Makes one website change with AI (D99). Not retried automatically: a failed change says why, and
 * the owner asks again.
 */
class RunSiteEdit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $editId) {}

    public function handle(SiteEditor $editor): void
    {
        $edit = SiteEdit::withoutGlobalScopes()->find($this->editId);
        if (! $edit) {
            return;
        }
        if (DemoGuard::isDemo($edit->organization_id)) {
            $edit->forceFill(['status' => 'failed', 'error' => 'AI changes are switched off for demo businesses.'])->save();

            return;
        }
        $editor->run($edit);
    }

    public function failed(?\Throwable $e): void
    {
        SiteEdit::withoutGlobalScopes()->whereKey($this->editId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error' => 'Something went wrong. Please try again.']);
    }
}
