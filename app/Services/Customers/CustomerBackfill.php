<?php

namespace App\Services\Customers;

use App\Actions\Customers\MatchOrCreateCustomer;
use App\Actions\Customers\RecordTimelineEvent;
use App\Enums\TimelineEventType;
use App\Models\CallLog;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds customers and their timelines from calls logged before the CRM existed (P2-3).
 * Idempotent: only calls without a customer are processed.
 */
class CustomerBackfill
{
    public function __construct(
        private readonly MatchOrCreateCustomer $match,
        private readonly RecordTimelineEvent $timeline,
    ) {}

    /**
     * @return array{calls_linked: int, customers_created: int, calls_without_identity: int}
     */
    public function run(bool $dryRun = false): array
    {
        $report = ['calls_linked' => 0, 'customers_created' => 0, 'calls_without_identity' => 0];
        $organizations = Organization::query()->get()->keyBy('id');

        DB::beginTransaction();

        try {
            CallLog::withoutGlobalScopes()
                ->whereNotNull('organization_id')
                ->whereNull('customer_id')
                ->orderBy('id')
                ->chunkById(500, function ($calls) use (&$report, $organizations) {
                    foreach ($calls as $call) {
                        $organization = $organizations->get($call->organization_id);
                        if (! $organization) {
                            continue;
                        }

                        $match = $this->match->handle($organization, [
                            'name' => $call->caller_name,
                            'phone' => $call->caller_phone,
                            'email' => $call->caller_email,
                            'address' => $call->service_location,
                        ], 'backfill');

                        if (! $match) {
                            $report['calls_without_identity']++;

                            continue;
                        }

                        $customer = $match['customer'];
                        CallLog::withoutGlobalScopes()->whereKey($call->id)->update(['customer_id' => $customer->id]);

                        if ($match['created']) {
                            $report['customers_created']++;
                            $this->timeline->handle($customer, TimelineEventType::CustomerCreated, 'Added from call history', occurredAt: $call->created_at);
                        }

                        $this->timeline->handle(
                            $customer,
                            TimelineEventType::CallIncoming,
                            'Incoming call · '.Str::headline((string) $call->reason_for_call),
                            trim('Outcome: '.$call->statusLabel().'. '.(string) $call->notes),
                            $call,
                            ['call_id' => $call->call_id, 'outcome' => $call->call_outcome],
                            $call->user_id,
                            $call->created_at,
                        );
                        $report['calls_linked']++;
                    }
                });

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $report;
    }
}
