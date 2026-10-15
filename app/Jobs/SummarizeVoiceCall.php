<?php

namespace App\Jobs;

use App\Exceptions\AiUnavailable;
use App\Models\CallLog;
use App\Models\VoiceCall;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A short AI summary of a recorded call from its transcript (D60). When the agent's call log has
 * no notes yet, the summary becomes them; otherwise it sits beside the agent's own notes.
 */
class SummarizeVoiceCall implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public const INSTRUCTIONS = <<<'TXT'
You summarise phone calls that a receptionist answered for a small business.
Write two to four short sentences in plain language: who called, what they wanted, what was agreed or booked, and anything the business must do next.
Use only what is in the transcript. Never invent names, prices, dates or promises. If the transcript is too short or unclear to tell, say so in one sentence.
TXT;

    public function __construct(public readonly int $callId) {}

    public function handle(AiProvider $ai): void
    {
        $call = VoiceCall::withoutGlobalScopes()->find($this->callId);
        if (! $call || empty($call->transcript)) {
            return;
        }
        if (! $ai->isConfigured()) {
            $call->forceFill(['summary_status' => 'unavailable'])->save();

            return;
        }

        $transcript = collect($call->transcript)->map(fn (array $line) => ($line['speaker'] === 'agent' ? 'Receptionist' : 'Caller').': '.$line['text'])->implode("\n");

        try {
            $response = $ai->complete(new AiRequest(
                self::INSTRUCTIONS,
                '',
                [['role' => 'user', 'content' => "Transcript:\n".mb_substr($transcript, 0, 60000)]],
                outputSchema: ['type' => 'object', 'properties' => ['summary' => ['type' => 'string']], 'required' => ['summary'], 'additionalProperties' => false],
                effort: 'low',
            ));
        } catch (AiUnavailable $e) {
            report($e);
            $call->forceFill(['summary_status' => 'failed'])->save();

            return;
        }

        $summary = trim((string) (json_decode($response->text, true)['summary'] ?? ''));
        if ($response->stopReason !== 'end_turn' || $summary === '') {
            $call->forceFill(['summary_status' => 'failed'])->save();

            return;
        }

        $call->forceFill(['summary' => $summary, 'summary_status' => 'done'])->save();

        if ($call->call_log_id && ($log = CallLog::withoutGlobalScopes()->find($call->call_log_id)) && blank($log->notes)) {
            $log->forceFill(['notes' => 'AI summary: '.$summary])->save();
        }
    }
}
