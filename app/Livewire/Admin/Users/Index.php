<?php

namespace App\Livewire\Admin\Users;

use App\Actions\Users\CreateUser;
use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\User;
use App\Services\Account\SignIn;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Everyone who signs in (spec §5): staff, agents and business owners. Add people, reset a password
 * to a temporary one, change a staff role, switch an account off. Only a Super Admin manages staff.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Users')]
class Index extends Component
{
    use PlatformAdminOnly;
    use WithPagination;

    public const TYPES = ['admin' => 'Staff', 'agent' => 'Agents', 'client' => 'Business owners'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $adding = false;

    /** @var array{name: string, email: string, phone: string, role: string, platform_role: string, business_name: string, password: string} */
    public array $draft = ['name' => '', 'email' => '', 'phone' => '', 'role' => 'client', 'platform_role' => '', 'business_name' => '', 'password' => ''];

    /** Shown once after creating a user or resetting a password, never stored. */
    public ?array $issued = null;

    public function mount(): void
    {
        $this->authorize('users.view');
        $this->resetDraft();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'type', 'status'], true)) {
            $this->resetPage();
        }
        if ($property === 'draft.role') {
            $this->draft['platform_role'] = (string) config("authorization.portal_defaults.{$this->draft['role']}", '');
        }
    }

    public function startAdding(): void
    {
        $this->authorize('users.create');
        $this->resetDraft();
        $this->resetValidation();
        $this->issued = null;
        $this->adding = true;
    }

    public function create(CreateUser $create): void
    {
        $this->authorize('users.create');
        $roles = $this->platformRoles($this->draft['role']);
        $this->validate([
            'draft.name' => ['required', 'string', 'max:255'],
            'draft.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'draft.phone' => ['nullable', 'string', 'max:30'],
            'draft.role' => ['required', Rule::in(array_keys(self::TYPES))],
            'draft.platform_role' => $roles ? ['required', Rule::in(array_keys($roles))] : ['nullable'],
            'draft.business_name' => ['nullable', 'string', 'max:255'],
            'draft.password' => ['nullable', 'string', 'min:8', 'max:100'],
        ], [], [
            'draft.name' => 'name', 'draft.email' => 'email', 'draft.phone' => 'phone', 'draft.role' => 'type',
            'draft.platform_role' => 'role', 'draft.business_name' => 'business name', 'draft.password' => 'temporary password',
        ]);
        $this->guardStaff($this->draft['role']);

        $password = $this->draft['password'] ?: self::temporaryPassword();
        $user = $create->handle($this->draft, $password);

        $this->adding = false;
        $this->issued = ['name' => $user->name, 'email' => $user->email, 'password' => $password, 'reason' => 'created'];
        $this->resetDraft();
        $this->dispatch('toast', type: 'success', message: "{$user->name} added.");
    }

    public function cancelAdding(): void
    {
        $this->adding = false;
        $this->resetValidation();
    }

    public function resetPassword(int $userId): void
    {
        $this->authorize('users.update');
        $user = $this->target($userId);

        $password = self::temporaryPassword();
        $user->forceFill(['password' => $password, 'must_change_password' => $user->role !== 'admin'])->save();
        app(SignIn::class)->signOutEverywhere($user);   // every browser and the mobile app

        $this->adding = false;
        $this->issued = ['name' => $user->name, 'email' => $user->email, 'password' => $password, 'reason' => 'reset'];
    }

    /** Lost phone: clear their two-step sign-in so they set it up again at their next sign-in. */
    public function resetTwoFactor(int $userId): void
    {
        $this->authorize('users.update');
        $user = $this->target($userId);
        abort_if($user->is(auth()->user()), 403, 'Ask another Super Admin to reset your own two-step sign-in.');

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        app(SignIn::class)->signOutEverywhere($user);
        app(Audit::class)->record('auth.two_factor_reset', $user);
        $this->dispatch('toast', type: 'success', message: "{$user->name}'s two-step sign-in is reset. They'll set it up again when they next sign in.");
    }

    public function toggleActive(int $userId): void
    {
        $this->authorize('users.update');
        $user = $this->target($userId);
        abort_if($user->is(auth()->user()), 403, 'You cannot switch off your own account.');

        $user->forceFill(['is_active' => ! $user->is_active])->save();
        if (! $user->is_active) {
            app(SignIn::class)->signOutEverywhere($user);
        }

        $this->dispatch('toast', type: 'success', message: $user->is_active ? "{$user->name} can sign in again." : "{$user->name} is switched off and signed out.");
    }

    public function changeRole(int $userId, string $role): void
    {
        $this->authorize('users.update');
        $user = $this->target($userId);
        abort_unless(array_key_exists($role, $this->platformRoles($user->role)), 422);
        abort_if($user->is(auth()->user()), 403, 'Ask another Super Admin to change your role.');

        $old = $user->getRoleNames()->implode(', ');
        $user->syncRoles([$role]);
        app(Audit::class)->record('user.role_changed', $user, ['role' => $old], ['role' => $role]);
        $this->dispatch('toast', type: 'success', message: "{$user->name} is now ".config("authorization.roles.{$role}.label").'.');
    }

    public function dismissIssued(): void
    {
        $this->issued = null;
    }

    public function render(): View
    {
        $users = User::query()
            ->with(['roles:id,name', 'organizations:id,ulid,name'])
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.addcslashes(trim($this->search), '%_\\').'%';
                $query->where(fn (Builder $q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term)
                    ->orWhere('unique_id', 'like', $term)->orWhereHas('organizations', fn (Builder $o) => $o->where('name', 'like', $term)));
            })
            ->when(array_key_exists($this->type, self::TYPES), fn (Builder $q) => $q->where('role', $this->type))
            ->when($this->status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($this->status === 'off', fn (Builder $q) => $q->where('is_active', false))
            ->when($this->status === 'pending', fn (Builder $q) => $q->where('must_change_password', true))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.admin.users.index', [
            'users' => $users,
            'counts' => User::query()->selectRaw('role, COUNT(*) as aggregate')->groupBy('role')->pluck('aggregate', 'role'),
            'types' => self::TYPES,
            'draftRoles' => $this->platformRoles($this->draft['role']),
            'canCreate' => auth()->user()->hasPermissionIn('users.create'),
            'canUpdate' => auth()->user()->hasPermissionIn('users.update'),
            'canManageStaff' => $this->canManageStaff(),
        ]);
    }

    /**
     * Platform roles a user of this portal type can hold.
     *
     * @return array<string, string>
     */
    public function platformRoles(string $type): array
    {
        $scope = ['admin' => 'platform', 'agent' => 'assigned'][$type] ?? null;

        return $scope === null ? [] : collect(config('authorization.roles'))
            ->filter(fn (array $role) => $role['scope'] === $scope)
            ->map(fn (array $role) => $role['label'])
            ->all();
    }

    public static function temporaryPassword(): string
    {
        // Easy to read out loud: no symbols, no look-alike characters.
        return collect(str_split(Str::password(24, symbols: false)))
            ->reject(fn (string $c) => in_array($c, ['0', 'O', 'o', '1', 'l', 'I'], true))
            ->take(12)->implode('');
    }

    private function target(int $userId): User
    {
        $user = User::findOrFail($userId);
        $this->guardStaff($user->role);

        return $user;
    }

    /** Staff accounts can take over the platform, so only a Super Admin adds or changes them. */
    private function guardStaff(string $type): void
    {
        abort_if($type === 'admin' && ! $this->canManageStaff(), 403, 'Only a Super Admin can manage staff accounts.');
    }

    private function canManageStaff(): bool
    {
        return auth()->user()->hasRole('super_admin');
    }

    private function resetDraft(): void
    {
        $this->draft = ['name' => '', 'email' => '', 'phone' => '', 'role' => 'client', 'platform_role' => '', 'business_name' => '', 'password' => ''];
    }
}
