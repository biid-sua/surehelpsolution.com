<?php

namespace App\Console\Commands;

use App\Mail\SystemTestMail;
use App\Services\System\LaunchReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Before and after going live (D79): what's ready, what must be fixed, which features are still off,
 * and the exact addresses to register with each provider.
 */
class LaunchCheck extends Command
{
    protected $signature = 'launch:check {--urls : List the callback and webhook addresses to register} {--mail= : Send a test email to this address}';

    protected $description = 'Check this installation is ready to go live, and list the addresses to register with providers';

    public function handle(LaunchReadiness $readiness): int
    {
        if ($this->option('urls')) {
            foreach ($readiness->urls() as $where => $urls) {
                $this->newLine();
                $this->line("<options=bold>{$where}</>");
                foreach ($urls as $u) {
                    $this->line("  {$u['label']}: <info>{$u['url']}</info>");
                }
            }

            return self::SUCCESS;
        }

        if ($to = $this->option('mail')) {
            Mail::to($to)->send(new SystemTestMail);
            $this->info("Test email sent to {$to} with the ".config('mail.default').' mailer. Check the inbox (and spam).');
        }

        $checks = $readiness->checks();
        $marks = [LaunchReadiness::OK => '<info>✓</info>', LaunchReadiness::WARN => '<comment>!</comment>', LaunchReadiness::FAIL => '<error>✗</error>', LaunchReadiness::OFF => '<fg=gray>○</>'];
        foreach (collect($checks)->groupBy('group') as $group => $rows) {
            $this->newLine();
            $this->line("<options=bold>{$group}</>");
            foreach ($rows as $row) {
                $this->line('  '.$marks[$row['status']].' '.$row['label'].' <fg=gray>('.$row['detail'].')</>');
                if ($row['fix'] !== '') {
                    $this->line('      → '.$row['fix']);
                }
            }
        }

        $s = $readiness->summary($checks);
        $this->newLine();
        $this->line("{$s['ok']} ready, {$s['fail']} to fix, {$s['warn']} recommended, {$s['off']} features off.");
        if ($s['fail'] > 0) {
            $this->error('Not ready to go live yet: fix the ✗ items first.');

            return self::FAILURE;
        }
        $this->info('Ready to go live. Features marked ○ stay hidden until their settings are filled in.');

        return self::SUCCESS;
    }
}
