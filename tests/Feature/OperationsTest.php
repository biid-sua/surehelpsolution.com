<?php

namespace Tests\Feature;

use App\Mail\ErrorAlertMail;
use App\Services\System\ErrorAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Operations basics (D91): error alerts, /health and database backups.
 */
class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_errors_are_emailed_once_per_window(): void
    {
        Mail::fake();
        config(['ops.alert_email' => 'ops@surehelp.test', 'ops.alerts_in_tests' => true]);
        $e = new \RuntimeException('Twilio said no');
        app(ErrorAlerts::class)->handle($e);
        app(ErrorAlerts::class)->handle($e);   // the same one again: throttled

        Mail::assertSent(ErrorAlertMail::class, 1);
        Mail::assertSent(ErrorAlertMail::class, fn (ErrorAlertMail $m) => $m->hasTo('ops@surehelp.test') && $m->message === 'Twilio said no'
            && str_starts_with($m->where, 'tests/Feature/OperationsTest.php:'));
    }

    public function test_health_says_little_without_the_token(): void
    {
        config(['ops.health_token' => 'secret-token']);
        Cache::put('scheduler:heartbeat', now()->timestamp, 3600);

        $this->getJson('/health')->assertOk()->assertExactJson(['status' => 'ok']);
        $this->getJson('/health?token=secret-token')->assertOk()->assertJsonPath('checks.database', true)->assertJsonPath('checks.backup', false);

        Cache::forget('scheduler:heartbeat');
        $this->getJson('/health')->assertStatus(503)->assertJsonPath('status', 'failing');
    }

    public function test_the_sqlite_database_is_backed_up_and_old_backups_removed(): void
    {
        $db = storage_path('framework/testing-backup.sqlite');
        File::put($db, 'SQLite format 3 pretend database');
        config(['database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $db], 'database.default' => 'backup_test', 'ops.backups.keep_days' => 7]);
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);
        File::put($old = $dir.'/db-20200101-000000.sql.gz', 'old');
        touch($old, now()->subDays(30)->timestamp);

        $this->artisan('backup:database')->assertSuccessful();

        $files = glob($dir.'/db-*.sql.gz');
        $this->assertCount(1, $files);
        $this->assertSame('SQLite format 3 pretend database', gzdecode((string) file_get_contents($files[0])));
        $this->assertNotNull(Cache::get('backup:last'));
        File::delete([$db, ...$files]);
        config(['database.default' => 'sqlite']);
    }
}
