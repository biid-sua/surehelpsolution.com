<?php

namespace App\Jobs;

use App\Models\SearchConsoleConnection;
use App\Services\Seo\SearchConsole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PullSearchConsole implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $connectionId) {}

    public function handle(SearchConsole $console): void
    {
        $connection = SearchConsoleConnection::withoutGlobalScopes()->find($this->connectionId);
        if ($connection) {
            $console->pull($connection);
        }
    }
}
