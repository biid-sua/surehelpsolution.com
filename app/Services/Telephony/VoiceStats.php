<?php

namespace App\Services\Telephony;

use App\Models\VoiceCall;
use Carbon\CarbonInterface;

/**
 * Real phone numbers for a period (D60): how many calls came in, how many we answered, how
 * long callers waited and how long calls lasted. Calls still ringing or on the line aren't counted.
 */
class VoiceStats
{
    /**
     * @return array{total: int, answered: int, to_owner: int, missed: int, voicemail: int, answer_rate: ?int, avg_wait: ?int, avg_talk: ?int}
     */
    public function summary(?int $organizationId, CarbonInterface $from, CarbonInterface $to): array
    {
        $calls = VoiceCall::withoutGlobalScopes()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereBetween('started_at', [$from, $to])
            ->whereNotIn('status', [VoiceCall::RINGING, VoiceCall::IN_PROGRESS])
            ->get(['status', 'answered_at', 'wait_seconds', 'duration_seconds']);

        $answered = $calls->whereNotNull('answered_at');
        $total = $calls->count();

        return [
            'total' => $total,
            'answered' => $answered->count(),
            'to_owner' => $calls->where('status', VoiceCall::TRANSFERRED)->whereNull('answered_at')->count(),
            'missed' => $calls->whereIn('status', [VoiceCall::MISSED, VoiceCall::FAILED])->count(),
            'voicemail' => $calls->where('status', VoiceCall::VOICEMAIL)->count(),
            'answer_rate' => $total > 0 ? (int) round($answered->count() / $total * 100) : null,
            'avg_wait' => $answered->count() > 0 ? (int) round($answered->avg('wait_seconds')) : null,
            'avg_talk' => $answered->whereNotNull('duration_seconds')->count() > 0 ? (int) round($answered->whereNotNull('duration_seconds')->avg('duration_seconds')) : null,
        ];
    }
}
