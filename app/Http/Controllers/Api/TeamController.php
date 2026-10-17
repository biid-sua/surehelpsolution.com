<?php

namespace App\Http\Controllers\Api;

use App\Actions\Team\InviteMember;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Account\SignIn;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The business's team in the app (API-12), with the web's rules: anyone with users.view sees it,
 * managers invite staff, owners invite managers, change roles and remove people. Nobody changes the
 * owner's access or their own.
 */
class TeamController extends Controller
{
    public function __construct(private readonly Audit $audit) {}

    public function index(Request $request, CurrentOrganization $current): JsonResponse
    {
        $organization = $current->get();
        $user = $request->user();
        $members = $organization->members()->wherePivot('status', 'active')
            ->orderByRaw("CASE organization_user.role WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END")->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email', 'users.last_login_at', 'users.is_active']);
        $invitations = OrganizationInvitation::query()->where('organization_id', $organization->id)->pending()->with('inviter:id,name')->latest()->get();

        return ApiResponse::success([
            'members' => $members->map(fn (User $m): array => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'role' => $m->id === $organization->owner_user_id ? 'owner' : $m->getRelationValue('pivot')->role,
                'last_login_at' => $m->last_login_at ? Carbon::parse($m->last_login_at)->toIso8601String() : null,
                'is_you' => $m->id === $user->id,
            ])->values(),
            'invitations' => $invitations->map(fn (OrganizationInvitation $i) => [
                'id' => $i->id, 'email' => $i->email, 'role' => $i->role, 'invited_by' => $i->inviter?->name,
                'expires_at' => $i->expires_at->toIso8601String(),
            ])->values(),
            'roles' => OrganizationInvitation::ROLES,
            'can_invite' => array_keys($this->invitable($user, $organization)),
            'can_manage' => $user->hasPermissionIn('users.delete', $organization),
        ]);
    }

    public function invite(Request $request, CurrentOrganization $current, InviteMember $invite): JsonResponse
    {
        $organization = $current->get();
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_keys($this->invitable($request->user(), $organization)))],
        ], ['role.in' => 'You can invite staff. Ask the owner to invite a manager.']);
        $invitation = $invite->handle($organization, $data['email'], $data['role'], $request->user());

        return ApiResponse::success(['invitation' => ['id' => $invitation->id, 'email' => $invitation->email, 'role' => $invitation->role]], "Invitation sent to {$invitation->email}.", 201);
    }

    public function resend(Request $request, CurrentOrganization $current, InviteMember $invite, int $id): JsonResponse
    {
        $organization = $current->get();
        $invitation = $this->invitation($organization, $id);
        abort_unless(array_key_exists($invitation->role, $this->invitable($request->user(), $organization)), 403);
        $invite->handle($organization, $invitation->email, $invitation->role, $request->user());

        return ApiResponse::success(null, "Sent a new invitation to {$invitation->email}.");
    }

    public function revoke(CurrentOrganization $current, int $id): JsonResponse
    {
        $organization = $current->get();
        $invitation = $this->invitation($organization, $id);
        $invitation->forceFill(['revoked_at' => now()])->save();
        $this->audit->record('team.invitation_revoked', $invitation, organization: $organization, label: $invitation->email);

        return ApiResponse::success(null, 'Invitation cancelled; the link no longer works.');
    }

    public function changeRole(Request $request, CurrentOrganization $current, int $id): JsonResponse
    {
        $organization = $current->get();
        $role = $request->validate(['role' => ['required', Rule::in(array_keys(OrganizationInvitation::ROLES))]])['role'];
        $member = $this->member($request, $organization, $id);
        $old = $member->getRelationValue('pivot')->role;
        $organization->members()->updateExistingPivot($member->id, ['role' => $role]);
        $this->audit->record('team.role_changed', $member, ['role' => $old], ['role' => $role], organization: $organization);

        return ApiResponse::success(['id' => $member->id, 'role' => $role], "{$member->name} is now ".mb_strtolower(OrganizationInvitation::ROLES[$role]).'.');
    }

    public function remove(Request $request, CurrentOrganization $current, SignIn $signIn, int $id): JsonResponse
    {
        $organization = $current->get();
        $member = $this->member($request, $organization, $id);
        $organization->members()->detach($member->id);
        if ($member->organizations()->doesntExist()) {
            $member->forceFill(['is_active' => false])->save();
            $signIn->signOutEverywhere($member);
        }
        $this->audit->record('team.removed', $member, organization: $organization);

        return ApiResponse::success(null, "{$member->name} no longer has access to {$organization->name}.");
    }

    /** @return array<string, string> */
    private function invitable(User $user, Organization $organization): array
    {
        if (! $user->hasPermissionIn('users.create', $organization)) {
            return [];
        }

        return $user->hasPermissionIn('users.delete', $organization) ? OrganizationInvitation::ROLES : ['staff' => OrganizationInvitation::ROLES['staff']];
    }

    private function invitation(Organization $organization, int $id): OrganizationInvitation
    {
        return OrganizationInvitation::query()->where('organization_id', $organization->id)->pending()->findOrFail($id);
    }

    private function member(Request $request, Organization $organization, int $id): User
    {
        abort_if($id === $request->user()->id, 403, 'You can\'t change your own access.');
        abort_if($id === $organization->owner_user_id, 403, 'The owner\'s access can\'t be changed here.');

        return $organization->members()->wherePivot('status', 'active')->whereKey($id)->firstOrFail();
    }
}
