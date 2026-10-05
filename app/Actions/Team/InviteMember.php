<?php

namespace App\Actions\Team;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\Account\TeamInvitation;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A business owner (or manager, for staff) invites someone by email (spec AUTH-04).
 * Each person uses SureHelp with one business (D1), so an email that already has an account can't be invited.
 */
class InviteMember
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(Organization $organization, string $email, string $role, User $inviter): OrganizationInvitation
    {
        $email = mb_strtolower(trim($email));

        if (! array_key_exists($role, OrganizationInvitation::ROLES)) {
            throw ValidationException::withMessages(['role' => 'Choose manager or staff.']);
        }
        if ($organization->members()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This person is already on your team.']);
        }
        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email already has a SureHelp account, so it can\'t join another business. Use a different email for this person.']);
        }

        // Inviting again replaces the earlier invitation, so only the newest link works.
        OrganizationInvitation::query()->where('organization_id', $organization->id)->where('email', $email)->pending()->update(['revoked_at' => now()]);

        $token = Str::random(48);
        $invitation = OrganizationInvitation::create([
            'organization_id' => $organization->id,
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays((int) config('account.invitation_days', 7)),
        ]);
        $invitation->setRelation('organization', $organization)->setRelation('inviter', $inviter);

        Notification::route('mail', $email)->notify(new TeamInvitation($invitation, $token));
        $this->audit->record('team.invited', $invitation, new: ['email' => $email, 'role' => $role], organization: $organization, label: $email);

        return $invitation;
    }
}
