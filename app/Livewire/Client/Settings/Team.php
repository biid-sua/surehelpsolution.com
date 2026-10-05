<?php

namespace App\Livewire\Client\Settings;

use App\Actions\Team\InviteMember;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Account\SignIn;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The business's own team (spec AUTH-04): invite managers and staff by email, change roles,
 * remove people. Owners do everything; managers can invite staff. Agents and SureHelp staff
 * are not listed here: they are managed by SureHelp.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Team')]
class Team extends Component
{
    use ScopedToOrganization;

    public string $email = '';

    public string $role = 'staff';

    public function mount(): void
    {
        $this->authorize('users.view', $this->organization());
    }

    public function invite(InviteMember $invite): void
    {
        $this->authorize('users.create', $this->organization());
        $this->resetValidation();
        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_keys($this->invitableRoles()))],
        ], ['role.in' => 'You can invite staff. Ask the owner to invite a manager.']);

        $invite->handle($this->organization(), $this->email, $this->role, auth()->user());
        $this->dispatch('toast', type: 'success', message: "Invitation sent to {$this->email}.");
        $this->reset('email');
        $this->role = 'staff';
    }

    public function resend(int $id, InviteMember $invite): void
    {
        $this->authorize('users.create', $this->organization());
        $invitation = $this->invitation($id);
        abort_unless(array_key_exists($invitation->role, $this->invitableRoles()), 403);
        $invite->handle($this->organization(), $invitation->email, $invitation->role, auth()->user());
        $this->dispatch('toast', type: 'success', message: "Sent a new invitation to {$invitation->email}.");
    }

    public function revoke(int $id, Audit $audit): void
    {
        $this->authorize('users.create', $this->organization());
        $invitation = $this->invitation($id);
        $invitation->forceFill(['revoked_at' => now()])->save();
        $audit->record('team.invitation_revoked', $invitation, organization: $this->organization(), label: $invitation->email);
        $this->dispatch('toast', type: 'success', message: 'Invitation cancelled; the link no longer works.');
    }

    public function changeRole(int $userId, string $role, Audit $audit): void
    {
        $this->authorize('users.delete', $this->organization());   // owners only
        abort_unless(array_key_exists($role, OrganizationInvitation::ROLES), 422);
        $member = $this->member($userId);

        $old = $this->organization()->members()->whereKey($member->id)->first()->getRelationValue('pivot')->role;
        $this->organization()->members()->updateExistingPivot($member->id, ['role' => $role]);
        $audit->record('team.role_changed', $member, ['role' => $old], ['role' => $role], organization: $this->organization());
        $this->dispatch('toast', type: 'success', message: "{$member->name} is now ".mb_strtolower(OrganizationInvitation::ROLES[$role]).'.');
    }

    public function remove(int $userId, Audit $audit, SignIn $signIn): void
    {
        $this->authorize('users.delete', $this->organization());
        $member = $this->member($userId);

        $this->organization()->members()->detach($member->id);
        // Each person works with one business (D1): without it, their account has nothing left to open.
        if ($member->organizations()->doesntExist()) {
            $member->forceFill(['is_active' => false])->save();
            $signIn->signOutEverywhere($member);
        }
        $audit->record('team.removed', $member, organization: $this->organization());
        $this->dispatch('toast', type: 'success', message: "{$member->name} no longer has access to {$this->organization()->name}.");
    }

    public function render(): View
    {
        $organization = $this->organization();

        return view('livewire.client.settings.team', [
            'organization' => $organization,
            'members' => $organization->members()->wherePivot('status', 'active')->orderByRaw("CASE organization_user.role WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END")->orderBy('name')
                ->get(['users.id', 'users.name', 'users.email', 'users.last_login_at', 'users.is_active']),
            'invitations' => OrganizationInvitation::query()->where('organization_id', $organization->id)->pending()->with('inviter:id,name')->latest()->get(),
            'roles' => OrganizationInvitation::ROLES,
            'invitable' => $this->invitableRoles(),
            'canInvite' => auth()->user()->hasPermissionIn('users.create', $organization),
            'canManage' => auth()->user()->hasPermissionIn('users.delete', $organization),
        ]);
    }

    /** @return array<string, string> */
    private function invitableRoles(): array
    {
        return auth()->user()->hasPermissionIn('users.delete', $this->organization())
            ? OrganizationInvitation::ROLES
            : ['staff' => OrganizationInvitation::ROLES['staff']];
    }

    private function invitation(int $id): OrganizationInvitation
    {
        return OrganizationInvitation::query()->where('organization_id', $this->organization()->id)->pending()->findOrFail($id);
    }

    /** A member other than the owner and yourself. */
    private function member(int $userId): User
    {
        abort_if($userId === auth()->id(), 403, 'You can\'t change your own access.');
        abort_if($userId === $this->organization()->owner_user_id, 403, 'The owner\'s access can\'t be changed here.');

        return $this->organization()->members()->whereKey($userId)->firstOrFail();
    }
}
