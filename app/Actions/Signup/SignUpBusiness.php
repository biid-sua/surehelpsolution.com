<?php

namespace App\Actions\Signup;

use App\Actions\Billing\ManageSubscription;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\NewSignup;
use App\Services\Account\LegalDocuments;
use App\Services\Billing\Referrals;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * A business creates its own account from the website (D65): the owner, the business (onboarding,
 * so staff still connect its phone line and go live), the chosen plan's free trial and the accepted
 * terms. The owner confirms their email before using the portal; staff hear about every sign-up.
 */
class SignUpBusiness
{
    public function __construct(
        private readonly ProvisionUserTenancy $tenancy,
        private readonly ManageSubscription $subscriptions,
        private readonly LegalDocuments $legal,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array{business: string, name: string, email: string, phone?: ?string, password: string, ref?: ?string}  $data
     */
    public function handle(array $data, ?Plan $plan, ?Request $request = null): User
    {
        $user = DB::transaction(function () use ($data, $plan, $request) {
            $user = User::create([
                'name' => trim($data['name']),
                'email' => mb_strtolower(trim($data['email'])),
                'phone' => ($data['phone'] ?? null) ?: null,
                'password' => $data['password'],
                'role' => 'client',
                'is_active' => true,
                'must_change_password' => false,
            ]);

            /** @var Organization $organization */
            $organization = $this->tenancy->handle($user);
            $business = trim($data['business']);
            $organization->forceFill([
                'name' => $business,
                'slug' => Organization::uniqueSlug($business),
                'status' => OrganizationStatus::Onboarding,
                'signed_up_at' => now(),
            ])->save();

            if ($plan) {
                $this->subscriptions->subscribe($organization, $plan, $user);
            }
            app(Referrals::class)->signedUp($organization, $data['ref'] ?? null);
            $this->legal->accept($user, $request);
            $this->audit->record('organization.signed_up', $organization, new: ['plan' => $plan?->slug, 'ip' => $request?->ip()],
                organization: $organization, actor: $user, label: $business);

            return $user;
        });

        $user->sendEmailVerificationNotification();

        $staff = User::query()->where('role', 'admin')->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->hasPermissionIn('organization.update'));
        Notification::send($staff, new NewSignup($user->organizations()->firstOrFail(), $user, $plan));

        return $user;
    }
}
