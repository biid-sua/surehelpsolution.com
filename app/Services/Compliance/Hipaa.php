<?php

namespace App\Services\Compliance;

use App\Enums\NotificationEvent;
use App\Models\LegalAcceptance;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\Audit;

/**
 * HIPAA mode (D117), for healthcare businesses that signed the Business Associate Agreement. While
 * it's on, patient details stay inside SureHelp:
 * - no AI reads their calls or messages (AI receptionist, AI messaging assistant, agent AI help,
 *   call transcripts and summaries), as SureHelp's AI providers aren't covered by a BAA;
 * - team alerts that would carry patient details go only to the app's own bell; email, text and
 *   push get a plain "you have new activity" note instead;
 * - opening a customer's record is written in the audit log.
 */
class Hipaa
{
    /** Features that send conversations to an AI provider. */
    public const AI_FEATURES = ['ai_assistant', 'ai_receptionist'];

    /** Alerts that name callers, patients or what they said. */
    public const SENSITIVE_EVENTS = [
        NotificationEvent::CallLogged, NotificationEvent::CallMissed, NotificationEvent::FollowUpCreated, NotificationEvent::FollowUpOverdue,
        NotificationEvent::TaskAssigned, NotificationEvent::EscalationCreated, NotificationEvent::AppointmentCreated, NotificationEvent::AppointmentUpdated,
        NotificationEvent::AppointmentCancelled, NotificationEvent::MessageReceived, NotificationEvent::CustomerUnhappy, NotificationEvent::CustomerPaid,
        NotificationEvent::LeadReceived, NotificationEvent::EstimateAnswered, NotificationEvent::AutomationNotice,
    ];

    public static function on(int|Organization|null $organization): bool
    {
        if ($organization instanceof Organization) {
            return (bool) $organization->hipaa_mode;
        }

        return $organization !== null && (bool) Organization::withoutGlobalScopes()->whereKey($organization)->value('hipaa_mode');
    }

    public static function sensitive(?string $event): bool
    {
        return $event !== null && in_array(NotificationEvent::tryFrom($event), self::SENSITIVE_EVENTS, true);
    }

    /** Turned on by an owner, who signs the BAA for the business in doing so. */
    public function enable(Organization $organization, User $by, string $ip, ?string $agent): void
    {
        LegalAcceptance::create(['user_id' => $by->id, 'document' => 'baa', 'version' => (string) config('account.baa_version', '2026-10-01'),
            'accepted_at' => now(), 'ip_address' => $ip, 'user_agent' => $agent ? mb_substr($agent, 0, 255) : null]);
        $organization->forceFill(['hipaa_mode' => true, 'baa_accepted_at' => now(), 'baa_accepted_by_user_id' => $by->id])->save();
        app(Audit::class)->record('compliance.hipaa_enabled', $organization, new: ['hipaa_mode' => true], organization: $organization, actor: $by, label: 'HIPAA mode on');
    }

    public function disable(Organization $organization, User $by): void
    {
        $organization->forceFill(['hipaa_mode' => false])->save();
        app(Audit::class)->record('compliance.hipaa_disabled', $organization, old: ['hipaa_mode' => true], new: ['hipaa_mode' => false], organization: $organization, actor: $by, label: 'HIPAA mode off');
    }
}
