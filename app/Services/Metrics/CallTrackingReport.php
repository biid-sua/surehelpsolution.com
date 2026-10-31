<?php

namespace App\Services\Metrics;

use App\Enums\OutcomeCategory;
use App\Enums\ServicePriceType;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\VoiceCall;
use App\Services\Calls\CallOutcomes;
use Carbon\CarbonImmutable;

/**
 * Call tracking (D114): for each of the business's numbers, the calls it got in a month, how many were
 * answered, the jobs booked on them and what they were worth, and, where the business entered what
 * the channel costs, the cost per call and per booked job. The main number is its own row.
 */
class CallTrackingReport
{
    private const ANSWERED = [VoiceCall::COMPLETED, VoiceCall::TRANSFERRED, VoiceCall::IN_PROGRESS];

    public function __construct(private readonly CallOutcomes $outcomes) {}

    /**
     * @return list<array{line: PhoneNumber, source: string, calls: int, answered: int, missed: int, booked: int, value_cents: int|null, spend_cents: int|null, cost_per_call_cents: int|null, cost_per_job_cents: int|null}>
     */
    public function compute(Organization $organization, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $booked = $this->outcomes->keys($organization, OutcomeCategory::Booked) ?: ['-'];
        $average = $organization->average_job_value_cents ?: null;
        $lines = PhoneNumber::query()->forOrganization($organization)
            ->where(fn ($q) => $q->where('status', PhoneNumber::STATUS_ACTIVE)->orWhere('released_at', '>=', $start->utc()))
            ->orderByRaw('tracking_source IS NOT NULL')->orderBy('id')->get();

        $rows = [];
        foreach ($lines as $line) {
            $calls = VoiceCall::query()->forOrganization($organization)->where('phone_number_id', $line->id)->where('direction', 'inbound')
                ->whereBetween('created_at', [$start->utc(), $end->utc()])->get(['id', 'status', 'call_log_id']);
            $logIds = $calls->pluck('call_log_id')->filter()->all();
            $bookedIds = $logIds ? CallLog::query()->forOrganization($organization)->whereIn('id', $logIds)->whereIn('call_outcome', $booked)->pluck('id')->all() : [];
            $prices = $bookedIds ? Appointment::query()->forOrganization($organization)->whereIn('call_log_id', $bookedIds)->whereNotNull('service_id')
                ->with('service:id,price_type,price_cents')->get(['id', 'call_log_id', 'service_id'])
                ->mapWithKeys(fn (Appointment $a) => [$a->call_log_id => $a->service && $a->service->price_cents && $a->service->price_type !== ServicePriceType::QuoteRequired ? (int) $a->service->price_cents : null])
                ->all() : [];
            $value = null;
            foreach ($bookedIds as $id) {
                $cents = $prices[$id] ?? $average;
                $value = $cents === null ? $value : (int) $value + $cents;
            }
            $spend = $line->monthly_spend_cents ?: null;
            $count = $calls->count();
            $jobs = count($bookedIds);
            $answered = $calls->whereIn('status', self::ANSWERED)->count();
            $rows[] = [
                'line' => $line,
                'source' => $line->tracking_source ?: 'Main number',
                'calls' => $count,
                'answered' => $answered,
                'missed' => $count - $answered - $calls->where('status', VoiceCall::RINGING)->count(),
                'booked' => $jobs,
                'value_cents' => $value,
                'spend_cents' => $spend,
                'cost_per_call_cents' => $spend && $count ? intdiv($spend, $count) : null,
                'cost_per_job_cents' => $spend && $jobs ? intdiv($spend, $jobs) : null,
            ];
        }

        return $rows;
    }
}
