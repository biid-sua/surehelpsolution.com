<?php

namespace App\Services\Agencies;

use App\Enums\AgentAssignmentSource;
use App\Enums\OrganizationStatus;
use App\Models\Agency;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Escalation;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Models\VoiceCall;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Agency resellers (D118). An agency's people are managers of every business it looks after: they're
 * added when a business or a person joins the agency, and those memberships (marked with the agency)
 * leave with it. Businesses the agency adds itself start with the person who added them as owner.
 */
class AgencyDesk
{
    public const ROLES = ['owner' => 'Agency owner', 'member' => 'Agency team'];

    public function __construct(private readonly Audit $audit) {}

    public function addMember(Agency $agency, User $user, string $role, User $by): void
    {
        if (! $user->isClient()) {
            throw new \InvalidArgumentException('Only business-portal accounts can join an agency.');
        }
        DB::transaction(function () use ($agency, $user, $role) {
            $agency->members()->syncWithoutDetaching([$user->id => ['role' => array_key_exists($role, self::ROLES) ? $role : 'member']]);
            foreach ($agency->organizations as $organization) {
                $this->join($agency, $organization, $user);
            }
        });
        $this->audit->record('agency.member_added', $agency, new: ['user' => $user->email, 'role' => $role], actor: $by, label: $agency->name);
    }

    public function removeMember(Agency $agency, User $user, User $by): void
    {
        DB::transaction(function () use ($agency, $user) {
            $agency->members()->detach($user->id);
            DB::table('organization_user')->where('user_id', $user->id)->where('agency_id', $agency->id)->delete();
        });
        $this->audit->record('agency.member_removed', $agency, old: ['user' => $user->email], actor: $by, label: $agency->name);
    }

    public function addBusiness(Agency $agency, Organization $organization, User $by): void
    {
        DB::transaction(function () use ($agency, $organization) {
            if ($organization->agency_id && $organization->agency_id !== $agency->id) {
                $this->leave($organization);
            }
            $organization->forceFill(['agency_id' => $agency->id])->save();
            foreach ($agency->members as $member) {
                $this->join($agency, $organization, $member);
            }
        });
        $this->audit->record('agency.business_added', $organization, new: ['agency' => $agency->name], organization: $organization, actor: $by, label: $agency->name);
    }

    public function removeBusiness(Agency $agency, Organization $organization, User $by): void
    {
        abort_unless($organization->agency_id === $agency->id, 404);
        DB::transaction(fn () => $this->leave($organization));
        $this->audit->record('agency.business_removed', $organization, old: ['agency' => $agency->name], organization: $organization, actor: $by, label: $agency->name);
    }

    /** A new client business the agency sets up itself; the agency person who adds it owns it. */
    public function createBusiness(Agency $agency, string $name, string $timezone, User $by): Organization
    {
        $organization = DB::transaction(function () use ($agency, $name, $timezone, $by) {
            $organization = Organization::create(['name' => $name, 'status' => OrganizationStatus::Onboarding, 'timezone' => $timezone, 'owner_user_id' => $by->id]);
            $organization->members()->attach($by->id, ['role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
            $organization->forceFill(['agency_id' => $agency->id])->save();
            foreach ($agency->members as $member) {
                $this->join($agency, $organization, $member);
            }
            if (config('tenancy.auto_assign_agents')) {
                User::where('role', 'agent')->where('is_active', true)->where('is_demo', false)
                    ->each(fn (User $agent) => $organization->assignAgent($agent, AgentAssignmentSource::Automatic));
            }

            return $organization;
        });
        $this->audit->record('agency.business_created', $organization, new: ['agency' => $agency->name], organization: $organization, actor: $by, label: $name);

        return $organization;
    }

    /**
     * Each business this month: calls answered and missed, jobs booked, urgent matters waiting, and
     * what it paid SureHelp; with the agency's share. Read across businesses, so outside the current
     * business's scope.
     *
     * @return array{rows: list<array<string, mixed>>, paid_cents: int, commission_cents: int}
     */
    public function dashboard(Agency $agency, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = [];
        $paid = 0;
        $window = [$start->utc(), $end->utc()];
        foreach ($agency->organizations()->orderBy('name')->get() as $organization) {
            $paidCents = (int) Invoice::withoutGlobalScopes()->where('organization_id', $organization->id)->whereBetween('paid_at', $window)->sum('amount_paid_cents');
            $paid += $paidCents;
            $rows[] = [
                'organization' => $organization,
                'calls' => CallLog::withoutGlobalScopes()->where('organization_id', $organization->id)->whereBetween('created_at', $window)->count(),
                'missed' => VoiceCall::withoutGlobalScopes()->where('organization_id', $organization->id)->where('status', VoiceCall::MISSED)->whereBetween('created_at', $window)->count(),
                'booked' => Appointment::withoutGlobalScopes()->where('organization_id', $organization->id)->whereBetween('created_at', $window)->where('source', '!=', 'calendar')->count(),
                'urgent' => Escalation::withoutGlobalScopes()->where('organization_id', $organization->id)->whereIn('status', ['open', 'acknowledged'])->count(),
                'paid_cents' => $paidCents,
            ];
        }

        return ['rows' => $rows, 'paid_cents' => $paid, 'commission_cents' => (int) round($paid * (float) $agency->commission_percent / 100)];
    }

    private function join(Agency $agency, Organization $organization, User $user): void
    {
        if (! $organization->members()->whereKey($user->id)->exists()) {
            $organization->members()->attach($user->id, ['role' => 'manager', 'status' => 'active', 'joined_at' => now(), 'agency_id' => $agency->id]);
        }
    }

    private function leave(Organization $organization): void
    {
        DB::table('organization_user')->where('organization_id', $organization->id)->whereNotNull('agency_id')->delete();
        $organization->forceFill(['agency_id' => null])->save();
    }
}
