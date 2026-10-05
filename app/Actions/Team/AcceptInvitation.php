<?php

namespace App\Actions\Team;

use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Account\LegalDocuments;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a valid invitation into an account on the business's team. The email is confirmed by
 * the act of opening the emailed link, and the terms are accepted on the same form.
 */
class AcceptInvitation
{
    public function __construct(private readonly LegalDocuments $legal, private readonly Audit $audit) {}

    public function handle(OrganizationInvitation $invitation, string $name, string $password, ?Request $request = null): User
    {
        return DB::transaction(function () use ($invitation, $name, $password, $request) {
            $invitation = OrganizationInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            if (! $invitation->isPending()) {
                throw ValidationException::withMessages(['invitation' => 'This invitation is no longer valid.']);
            }
            if (User::where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['invitation' => 'This email already has a SureHelp account. Sign in instead.']);
            }

            $user = User::create([
                'name' => trim($name),
                'email' => $invitation->email,
                'password' => $password,
                'role' => 'client',
                'is_active' => true,
                'must_change_password' => false,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $invitation->organization->members()->attach($user->id, ['role' => $invitation->role, 'status' => 'active', 'invited_at' => $invitation->created_at, 'joined_at' => now()]);
            $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
            $this->legal->accept($user, $request);
            $this->audit->record('team.joined', $user, new: ['role' => $invitation->role], organization: $invitation->organization, actor: $user);

            return $user;
        });
    }
}
