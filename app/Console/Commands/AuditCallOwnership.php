<?php

namespace App\Console\Commands;

use App\Models\CallLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Read-only report: how call logs map to client accounts today.
 *
 * Feeds the tenancy decisions in docs/implementation-plan.md (D1, D2, D4)
 * before calls are moved under organizations. Makes no changes.
 */
class AuditCallOwnership extends Command
{
    protected $signature = 'audit:call-ownership {--details : List the call IDs in each problem group}';

    protected $description = 'Report which call logs map cleanly to a client account (read-only)';

    public function handle(): int
    {
        $clients = User::where('role', 'client')->get(['id', 'name', 'email']);
        $clientIds = $clients->pluck('id')->map(fn ($id) => (string) $id)->all();
        /** @var array<string, string> $clientIdByEmail */
        $clientIdByEmail = [];
        foreach ($clients as $client) {
            if ($client->email) {
                $clientIdByEmail[strtolower($client->email)] = (string) $client->id;
            }
        }

        $groups = [
            'mapped' => [],          // client_id points at a client user
            'no_client' => [],       // client_id empty
            'invalid_client' => [],  // client_id set but not a client user
            'email_only' => [],      // shown to a client only because caller_email matches their email
        ];

        CallLog::query()
            ->select(['id', 'call_id', 'client_id', 'caller_email'])
            ->orderBy('id')
            ->chunk(1000, function ($logs) use (&$groups, $clientIds, $clientIdByEmail) {
                foreach ($logs as $log) {
                    $clientId = trim((string) $log->client_id);
                    $emailOwner = $log->caller_email ? ($clientIdByEmail[strtolower($log->caller_email)] ?? null) : null;

                    if ($clientId === '') {
                        $groups['no_client'][] = $log->call_id;
                    } elseif (! in_array($clientId, $clientIds, true)) {
                        $groups['invalid_client'][] = $log->call_id;
                    } else {
                        $groups['mapped'][] = $log->call_id;
                    }

                    if ($emailOwner !== null && $emailOwner !== $clientId) {
                        $groups['email_only'][] = $log->call_id;
                    }
                }
            });

        $total = count($groups['mapped']) + count($groups['no_client']) + count($groups['invalid_client']);

        $this->info("Call logs: {$total}   Client accounts: {$clients->count()}");
        $this->table(['Group', 'Calls', 'Meaning'], [
            ['mapped', count($groups['mapped']), 'client_id points to a client account — migrates cleanly'],
            ['no_client', count($groups['no_client']), 'no client_id — needs a decision (D2)'],
            ['invalid_client', count($groups['invalid_client']), 'client_id is not a client account — needs a decision (D2)'],
            ['email_only', count($groups['email_only']), 'shown to a client ONLY because the caller email matches theirs — a leak, or a call that disappears once R3 is fixed'],
        ]);

        if ($this->option('details')) {
            foreach (['no_client', 'invalid_client', 'email_only'] as $group) {
                if ($groups[$group]) {
                    $this->line("<comment>{$group}:</comment> ".implode(', ', $groups[$group]));
                }
            }
        }

        return self::SUCCESS;
    }
}
