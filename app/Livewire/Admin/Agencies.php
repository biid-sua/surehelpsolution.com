<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Agency;
use App\Models\AgencyStatement;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agencies\AgencyDesk;
use App\Services\Business\Branding;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin › Agencies (D118): set up a reseller agency, its brand and share, its people, and the
 * businesses it looks after.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Agencies')]
class Agencies extends Component
{
    use PlatformAdminOnly;

    public ?string $open = null;   // agency ulid

    /** @var array{name: string, brand_color: string, logo_url: string, commission_percent: string} */
    public array $form = ['name' => '', 'brand_color' => '', 'logo_url' => '', 'commission_percent' => '20'];

    public string $memberEmail = '';

    public string $memberRole = 'member';

    public string $business = '';   // organization ulid or name

    /** @var array<int, string> payment reference typed per statement id */
    public array $references = [];

    public function mount(): void
    {
        $this->authorize('settings.view');
    }

    public function edit(?string $ulid = null): void
    {
        $this->authorize('settings.view');
        $agency = $ulid ? $this->agency($ulid) : null;
        $this->open = $agency?->ulid;
        $this->form = ['name' => (string) $agency?->name, 'brand_color' => (string) $agency?->brand_color, 'logo_url' => (string) $agency?->logo_url,
            'commission_percent' => $agency ? (string) (float) $agency->commission_percent : '20'];
        $this->resetErrorBag();
    }

    public function save(Audit $audit): void
    {
        $this->authorize('settings.manage');
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:120'],
            'form.brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form.logo_url' => ['nullable', 'url:https', 'max:500'],
            'form.commission_percent' => ['required', 'numeric', 'min:0', 'max:60'],
        ], ['form.brand_color.regex' => 'Use a colour like #2563eb.'], ['form.name' => 'name', 'form.commission_percent' => 'share'])['form'];
        $values = ['name' => trim($data['name']), 'brand_color' => $data['brand_color'] ?: null, 'logo_url' => $data['logo_url'] ?: null, 'commission_percent' => $data['commission_percent']];
        $agency = $this->open ? $this->agency($this->open) : new Agency;
        $agency->fill($values)->save();
        $audit->record($this->open ? 'agency.updated' : 'agency.created', $agency, new: $values, label: $agency->name);
        $this->open = $agency->ulid;
        $agency->organizations->each(fn (Organization $o) => app(Branding::class)->forget($o));
        $this->dispatch('toast', type: 'success', message: 'Agency saved.');
    }

    public function addMember(AgencyDesk $desk): void
    {
        $this->authorize('settings.manage');
        $agency = $this->agency((string) $this->open);
        $this->validate(['memberEmail' => ['required', 'email'], 'memberRole' => [Rule::in(array_keys(AgencyDesk::ROLES))]], [], ['memberEmail' => 'email']);
        $user = User::query()->where('email', strtolower(trim($this->memberEmail)))->first();
        if (! $user || ! $user->isClient()) {
            throw ValidationException::withMessages(['memberEmail' => 'No business-portal account uses that email. Ask them to sign up first.']);
        }
        if (($other = Agency::of($user)) && $other->id !== $agency->id) {
            throw ValidationException::withMessages(['memberEmail' => 'That person already belongs to '.$other->name.'.']);
        }
        $desk->addMember($agency, $user, $this->memberRole, auth()->user());
        $this->reset(['memberEmail', 'memberRole']);
        $this->dispatch('toast', type: 'success', message: $user->name.' now looks after this agency\'s businesses.');
    }

    public function removeMember(int $userId, AgencyDesk $desk): void
    {
        $this->authorize('settings.manage');
        $agency = $this->agency((string) $this->open);
        $user = $agency->members()->whereKey($userId)->first() ?? abort(404);
        $desk->removeMember($agency, $user, auth()->user());
    }

    public function addBusiness(AgencyDesk $desk): void
    {
        $this->authorize('settings.manage');
        $agency = $this->agency((string) $this->open);
        $this->validate(['business' => ['required', 'string', 'max:120']], [], ['business' => 'business']);
        $organization = Organization::query()->where('ulid', trim($this->business))->orWhere('name', trim($this->business))->first();
        if (! $organization || $organization->is_internal || $organization->is_demo) {
            throw ValidationException::withMessages(['business' => 'No business found with that name or ID.']);
        }
        $desk->addBusiness($agency, $organization, auth()->user());
        app(Branding::class)->forget($organization);
        $this->reset('business');
        $this->dispatch('toast', type: 'success', message: $organization->name.' is now looked after by '.$agency->name.'.');
    }

    public function removeBusiness(string $ulid, AgencyDesk $desk): void
    {
        $this->authorize('settings.manage');
        $agency = $this->agency((string) $this->open);
        $organization = Organization::query()->where('ulid', $ulid)->firstOrFail();
        $desk->removeBusiness($agency, $organization, auth()->user());
        app(Branding::class)->forget($organization);
    }

    public function markPaid(int $statementId, AgencyDesk $desk): void
    {
        $this->authorize('settings.manage');
        $agency = $this->agency((string) $this->open);
        $statement = $agency->statements()->whereKey($statementId)->where('status', AgencyStatement::DUE)->first() ?? abort(404);
        $desk->markPaid($statement, (string) ($this->references[$statementId] ?? ''), auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Marked as paid.');
    }

    private function agency(string $ulid): Agency
    {
        return Agency::query()->where('ulid', $ulid)->first() ?? abort(404);
    }

    public function render(): View
    {
        return view('livewire.admin.agencies', [
            'agencies' => Agency::query()->withCount(['organizations', 'members'])->orderBy('name')->get(),
            'current' => $this->open ? Agency::query()->with(['members', 'organizations', 'statements' => fn ($q) => $q->latest('month')->limit(12)])->where('ulid', $this->open)->first() : null,
            'roles' => AgencyDesk::ROLES,
        ]);
    }
}
