<?php

namespace App\Services\Tenancy;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\AgentAssignmentSource;
use App\Enums\CallOwnershipSource;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moves legacy single-tenant data into organizations (docs/decisions.md D1–D3).
 *
 * Idempotent: only touches clients without an organization and calls without
 * an organization_id, so it is safe to run repeatedly.
 */
class TenancyBackfill
{
    public function __construct(private readonly ProvisionUserTenancy $provision) {}

    /**
     * @return array<string, int>
     */
    public function run(bool $dryRun = false): array
    {
        $report = [
            'organizations_created' => 0,
            'agent_assignments_created' => 0,
            'calls_by_client_id' => 0,
            'calls_by_email_match' => 0,
            'calls_unassigned' => 0,
        ];

        DB::beginTransaction();

        try {
            $this->createOrganizations($report);
            $this->assignAgents($report);
            $this->attributeCalls($report);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $report;
    }

    private function createOrganizations(array &$report): void
    {
        User::where('role', 'client')
            ->whereDoesntHave('organizations')
            ->orderBy('id')
            ->each(function (User $client) use (&$report) {
                $this->provision->handle($client, AgentAssignmentSource::Migration);
                $report['organizations_created']++;
            });
    }

    /**
     * D3: every active agent keeps serving every migrated organization.
     */
    private function assignAgents(array &$report): void
    {
        $agentIds = User::where('role', 'agent')->where('is_active', true)->pluck('id');

        Organization::query()->orderBy('id')->each(function (Organization $organization) use ($agentIds, &$report) {
            $missing = $agentIds->diff($organization->agents()->pluck('users.id'));

            foreach ($missing as $agentId) {
                $organization->agents()->attach($agentId, [
                    'source' => AgentAssignmentSource::Migration->value,
                    'is_primary' => false,
                ]);
                $report['agent_assignments_created']++;
            }
        });
    }

    /**
     * D2: client_id first, then a unique caller-email match (flagged), otherwise unassigned.
     */
    private function attributeCalls(array &$report): void
    {
        $clients = User::where('role', 'client')->with('organizations')->get(['id', 'email']);

        /** @var array<string, int> $orgByClientId client user id => organization id */
        $orgByClientId = [];
        /** @var array<string, int> $emailCounts */
        $emailCounts = [];

        foreach ($clients as $client) {
            $organizationId = $client->organizations->sortBy('id')->first()?->getKey();
            if ($organizationId) {
                $orgByClientId[(string) $client->id] = (int) $organizationId;
            }
            if ($client->email) {
                $email = strtolower($client->email);
                $emailCounts[$email] = ($emailCounts[$email] ?? 0) + 1;
            }
        }

        // Only emails that identify exactly one client are usable.
        /** @var array<string, int> $orgByEmail */
        $orgByEmail = [];
        foreach ($clients as $client) {
            $email = strtolower((string) $client->email);
            if ($email !== '' && $emailCounts[$email] === 1 && isset($orgByClientId[(string) $client->id])) {
                $orgByEmail[$email] = $orgByClientId[(string) $client->id];
            }
        }

        CallLog::withoutGlobalScopes()
            ->whereNull('organization_id')
            ->chunkById(500, function ($logs) use ($orgByClientId, $orgByEmail, &$report) {
                foreach ($logs as $log) {
                    $clientId = trim((string) $log->client_id);
                    $email = strtolower(trim((string) $log->caller_email));

                    if ($clientId !== '' && isset($orgByClientId[$clientId])) {
                        [$orgId, $source, $key] = [$orgByClientId[$clientId], CallOwnershipSource::ClientId, 'calls_by_client_id'];
                    } elseif ($email !== '' && isset($orgByEmail[$email])) {
                        [$orgId, $source, $key] = [$orgByEmail[$email], CallOwnershipSource::EmailMatch, 'calls_by_email_match'];
                    } else {
                        [$orgId, $source, $key] = [null, CallOwnershipSource::Unassigned, 'calls_unassigned'];
                    }

                    // Plain query update: no model events, no timestamps change.
                    CallLog::withoutGlobalScopes()->whereKey($log->getKey())->update([
                        'organization_id' => $orgId,
                        'ownership_source' => $source->value,
                    ]);

                    $report[$key]++;
                }
            });
    }
}
