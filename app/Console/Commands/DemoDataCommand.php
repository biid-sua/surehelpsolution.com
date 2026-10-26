<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Demo\DemoData;
use Illuminate\Console\Command;

/**
 * Demo data from the server (D94), for when the admin page can't run long enough: the same load and
 * removal as Admin › Demo data, recorded against a platform administrator.
 */
class DemoDataCommand extends Command
{
    protected $signature = 'demo:data {action : status, load or remove} {--admin= : Email of the administrator to record it against}';

    protected $description = 'Load or remove the demo businesses and their history (Admin › Demo data)';

    public function handle(DemoData $demo): int
    {
        $action = (string) $this->argument('action');
        if ($action === 'status') {
            $state = $demo->state();
            $this->line('Demo data: <info>'.$state['status'].'</info>'.($state['stuck'] ? ' (stuck: open Admin › Demo data to recover)' : ''));
            if (! empty($state['error'])) {
                $this->warn($state['error']);
            }

            return self::SUCCESS;
        }
        if (! in_array($action, ['load', 'remove'], true)) {
            $this->error('Use status, load or remove.');

            return self::INVALID;
        }

        $admin = User::query()->where('role', 'admin')->where('is_demo', false)
            ->when($this->option('admin'), fn ($q, $email) => $q->where('email', $email))->orderBy('id')->first();
        if (! $admin) {
            $this->error('No platform administrator found'.($this->option('admin') ? ' with that email.' : '.'));

            return self::FAILURE;
        }
        if ($action === 'remove' && ! $this->confirm('Remove all demo data, including anything added inside the demo businesses?', ! $this->input->isInteractive())) {
            return self::FAILURE;
        }
        if (! $demo->begin($action, $admin)) {
            $this->error('Can\'t '.$action.' now: the demo data is '.$demo->state()['status'].'.');

            return self::FAILURE;
        }

        $started = microtime(true);
        if ($action === 'load') {
            $rows = $demo->load($admin);
            $this->info(sprintf('Loaded %d rows in %.0fs.', array_sum($rows), microtime(true) - $started));
        } else {
            $result = $demo->remove($admin);
            $this->info(sprintf('Removed %d rows in %.0fs.', array_sum($result['deleted']), microtime(true) - $started));
            if ($result['kept'] !== []) {
                $this->warn('Kept because real data uses them: '.collect($result['kept'])->map(fn ($n, $t) => "{$t} ({$n})")->implode(', '));
            }
        }

        return self::SUCCESS;
    }
}
