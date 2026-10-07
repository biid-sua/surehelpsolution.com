<?php

namespace App\Actions\Calls;

use App\Actions\Customers\RecordTimelineEvent;
use App\Enums\TimelineEventType;
use App\Models\CallLog;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Validation\ValidationException;

/**
 * An agent adds to the notes of a call they took, for a while after it (AGT-14). Notes are only
 * appended, stamped with who and when, so what the business already read never silently changes.
 * Who may do it is CallLogPolicy::update (own call, calls.update in a company they still serve).
 */
class AddCallNote
{
    public const WINDOW_HOURS = 24;

    public const MAX_LENGTH = 2000;

    public function __construct(
        private readonly Audit $audit,
        private readonly RecordTimelineEvent $timeline,
    ) {}

    public static function open(CallLog $call): bool
    {
        return $call->created_at !== null && $call->created_at->greaterThan(now()->subHours(self::WINDOW_HOURS));
    }

    /** @throws ValidationException */
    public function handle(CallLog $call, string $note, User $actor): CallLog
    {
        $note = trim($note);
        if (! self::open($call)) {
            throw ValidationException::withMessages(['note' => ['Notes can be added for '.self::WINDOW_HOURS.' hours after a call. Add it to the customer instead.']]);
        }

        $stamp = '['.now($call->organization?->timezoneOrDefault() ?? config('app.timezone'))->format('j M Y, g:i A').', '.$actor->name.'] ';
        $notes = trim(((string) $call->notes)."\n\n".$stamp.$note);
        if (mb_strlen($notes) > 60000) {
            throw ValidationException::withMessages(['note' => ['This call has too many notes already.']]);
        }

        $call->forceFill(['notes' => $notes])->save();
        $this->audit->record('call.note_added', $call, new: ['note' => $note], actor: $actor, label: $call->call_id);

        if ($call->customer) {
            $this->timeline->handle($call->customer, TimelineEventType::NoteAdded, 'Note added to call '.$call->call_id, $note, $call, ['call' => $call->call_id], $actor->id);
        }

        return $call;
    }
}
