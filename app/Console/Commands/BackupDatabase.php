<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Nightly database backup (D91): a compressed dump in storage/app/backups, copied to BACKUP_DISK when
 * that's another disk (S3, for example), and old ones removed after BACKUP_KEEP_DAYS.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Back up the database (compressed) and remove old backups';

    public function handle(): int
    {
        $connection = (string) config('database.default');
        $config = (array) config("database.connections.{$connection}");
        $dir = storage_path('app/backups');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error('Can\'t create '.$dir);

            return self::FAILURE;
        }
        $name = 'db-'.now()->format('Ymd-His').'.sql.gz';
        $path = $dir.'/'.$name;

        try {
            match ($config['driver'] ?? '') {
                'mysql', 'mariadb' => $this->mysql($config, $path),
                'sqlite' => $this->sqlite((string) $config['database'], $path),
                default => throw new \RuntimeException('Backups support MySQL, MariaDB and SQLite.'),
            };
        } catch (\Throwable $e) {
            report($e);
            @unlink($path);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }
        chmod($path, 0600);

        $disk = (string) config('ops.backups.disk', 'local');
        if ($disk !== 'local') {
            $stream = fopen($path, 'r');
            Storage::disk($disk)->writeStream('backups/'.$name, $stream);
            is_resource($stream) && fclose($stream);
        }

        $keep = max(1, (int) config('ops.backups.keep_days', 14));
        foreach (glob($dir.'/db-*.sql.gz') ?: [] as $old) {
            if (filemtime($old) < now()->subDays($keep)->timestamp) {
                unlink($old);
            }
        }
        if ($disk !== 'local') {
            foreach (Storage::disk($disk)->files('backups') as $old) {
                if (Storage::disk($disk)->lastModified($old) < now()->subDays($keep)->timestamp) {
                    Storage::disk($disk)->delete($old);
                }
            }
        }

        Cache::forever('backup:last', now()->timestamp);
        $this->info('Backed up to '.$name.' ('.number_format(filesize($path) / 1024, 0).' KB)'.($disk !== 'local' ? ' and copied to '.$disk : '').'.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $config */
    private function mysql(array $config, string $path): void
    {
        $dump = new Process(array_values(array_filter([
            'mysqldump', '--single-transaction', '--quick', '--no-tablespaces', '--routines',
            '--host='.($config['host'] ?? '127.0.0.1'), '--port='.($config['port'] ?? 3306), '--user='.($config['username'] ?? ''),
            ($config['unix_socket'] ?? '') ? '--socket='.$config['unix_socket'] : null,
            (string) $config['database'],
        ])), null, ['MYSQL_PWD' => (string) ($config['password'] ?? '')], null, 1800);
        $out = gzopen($path, 'wb6');
        $dump->run(function ($type, $buffer) use ($out) {
            if ($type === Process::OUT) {
                gzwrite($out, $buffer);
            }
        });
        gzclose($out);
        if (! $dump->isSuccessful()) {
            throw new \RuntimeException(trim($dump->getErrorOutput()) ?: 'mysqldump failed');
        }
    }

    private function sqlite(string $database, string $path): void
    {
        if ($database === ':memory:' || ! is_file($database)) {
            throw new \RuntimeException('No SQLite file to back up.');
        }
        $in = fopen($database, 'rb');
        $out = gzopen($path, 'wb6');
        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1 << 20));
        }
        fclose($in);
        gzclose($out);
    }
}
