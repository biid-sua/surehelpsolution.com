<?php

namespace App\Services\Telephony;

use App\Models\Organization;
use App\Models\User;
use App\Models\VoiceCall;
use App\Services\Training\TrainingReadiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Whether each agent can take a call now (D60). The softphone checks in while its page is open;
 * an agent who stops checking in is offline, so calls never ring a closed tab.
 */
class AgentPresence
{
    public const OFFLINE = 'offline';

    public const AVAILABLE = 'available';

    public const AWAY = 'away';

    public const BUSY = 'busy';

    public const WRAP_UP = 'wrap_up';

    public const LABELS = [
        self::OFFLINE => 'Offline',
        self::AVAILABLE => 'Ready for calls',
        self::AWAY => 'Away',
        self::BUSY => 'On a call',
        self::WRAP_UP => 'Finishing up',
    ];

    public function set(User $agent, string $status): void
    {
        $agent->forceFill(['phone_status' => $status, 'phone_status_at' => now(), 'phone_seen_at' => now()])->save();
    }

    public function checkIn(User $agent): void
    {
        $agent->forceFill(['phone_seen_at' => now()])->save();
    }

    /** What the agent is now: offline when the softphone stopped checking in; wrap-up ends by itself. */
    public function status(User $agent): string
    {
        if ($agent->phone_seen_at === null || $agent->phone_seen_at->lt(now()->subSeconds((int) config('telephony.presence_seconds')))) {
            return self::OFFLINE;
        }
        if ($agent->phone_status === self::WRAP_UP && $agent->phone_status_at?->lte(now()->subSeconds((int) config('telephony.wrap_up_seconds')))) {
            return self::AVAILABLE;
        }

        return (string) $agent->phone_status;
    }

    /**
     * Agents to ring for a business, longest-waiting first: currently assigned to it, allowed to log
     * its calls (training included), softphone online, ready, and not already on a call.
     *
     * @return Collection<int, User>
     */
    public function availableFor(Organization $organization, ?int $limit = null): Collection
    {
        $wrapUpEnded = now()->subSeconds((int) config('telephony.wrap_up_seconds'));
        $readiness = app(TrainingReadiness::class);

        return User::query()
            ->where('is_active', true)
            ->whereHas('assignedOrganizations', fn (Builder $q) => $q->whereKey($organization->id))
            ->where('phone_seen_at', '>=', now()->subSeconds((int) config('telephony.presence_seconds')))
            ->where(fn (Builder $q) => $q->where('phone_status', self::AVAILABLE)
                ->orWhere(fn (Builder $w) => $w->where('phone_status', self::WRAP_UP)->where('phone_status_at', '<=', $wrapUpEnded)))
            ->whereNotIn('id', VoiceCall::withoutGlobalScopes()->where('status', VoiceCall::IN_PROGRESS)->whereNotNull('answered_by_user_id')->select('answered_by_user_id'))
            ->orderBy('phone_status_at')->orderBy('id')
            ->get()
            ->filter(fn (User $agent) => $agent->hasPermissionIn('calls.create', $organization) && $readiness->restriction($agent, $organization) === null)
            ->take($limit ?? (int) config('telephony.max_ringing'))
            ->values();
    }

    /** The browser phone's name for an agent at the vendor. */
    public static function identity(User $agent): string
    {
        return 'agent-'.$agent->id;
    }

    /** "client:agent-12" or "agent-12" → 12. */
    public static function userIdFrom(?string $identity): ?int
    {
        return preg_match('/(?:^|:)agent-(\d+)$/', (string) $identity, $m) ? (int) $m[1] : null;
    }
}
