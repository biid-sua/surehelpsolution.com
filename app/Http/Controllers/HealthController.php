<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * For uptime monitors (D91): 200 when the database, cache, scheduler and queue are all fine, 503
 * otherwise. Details only with ?token=HEALTH_TOKEN, so the outside world learns nothing.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $checks = [];
        $checks['database'] = rescue(fn () => DB::select('select 1 as ok')[0]->ok == 1, false, false);
        $checks['cache'] = rescue(function () {
            Cache::put('health:probe', $v = (string) microtime(true), 30);

            return Cache::get('health:probe') === $v;
        }, false, false);
        $beat = Cache::get('scheduler:heartbeat');
        $checks['scheduler'] = $beat && now()->timestamp - (int) $beat < 300;
        $checks['queue'] = rescue(fn () => config('queue.default') !== 'database' || ! Schema::hasTable('jobs')
            || ! DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<', now()->subMinutes(10)->timestamp)->exists(), false, false);
        $checks['disk'] = (@disk_free_space(storage_path()) ?: 0) > 500 * 1024 * 1024;
        $checks['backup'] = ($last = Cache::get('backup:last')) && now()->timestamp - (int) $last < 2 * 86400;

        // A missing backup is a warning for the team, not downtime for the monitor.
        $ok = collect($checks)->except('backup')->every(fn (bool $v) => $v);
        $token = (string) config('ops.health_token');
        $detailed = $token !== '' && hash_equals($token, (string) $request->query('token'));

        return response()->json($detailed ? ['status' => $ok ? 'ok' : 'failing', 'checks' => $checks, 'at' => now()->toIso8601String()] : ['status' => $ok ? 'ok' : 'failing'],
            $ok ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}
