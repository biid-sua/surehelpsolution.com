<?php

namespace App\Livewire\Client\Social;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\BusinessProfile;
use App\Models\SocialAccount;
use App\Models\SocialPostTarget;
use App\Services\Billing\Entitlements;
use App\Services\Social\SocialApproval;
use App\Services\Social\SocialManager;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Connected social accounts (spec §41): connect, choose which to post to, reconnect, remove,
 * and who must approve posts. Tokens are never public properties.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Social accounts')]
class Accounts extends Component
{
    use ScopedToOrganization;

    public string $approvalMode = 'owner';

    public function mount(SocialApproval $approval): void
    {
        $this->authorize('social.view', $this->organization());
        $this->approvalMode = $approval->mode($this->organization());

        if ($message = session('success')) {
            $this->dispatch('toast', type: 'success', message: $message);
        }
        if ($message = session('error')) {
            $this->dispatch('toast', type: 'error', message: $message);
        }
    }

    public function toggle(string $ulid, Entitlements $entitlements, Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('social.manage', $organization);
        $account = $this->account($ulid);

        if (! $account->is_enabled) {
            $entitlements->ensureRoomFor($organization, 'social_accounts',
                SocialAccount::query()->forOrganization($organization)->where('is_enabled', true)->count(), 'accounts', 'social accounts');
        }

        $account->forceFill(['is_enabled' => ! $account->is_enabled])->save();
        $audit->record($account->is_enabled ? 'social.account_enabled' : 'social.account_disabled', $account, organization: $organization, label: $account->displayName());
    }

    public function remove(string $ulid, Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('social.manage', $organization);
        $account = $this->account($ulid);

        $pending = SocialPostTarget::query()->where('social_account_id', $account->id)->where('status', SocialPostTarget::PENDING)->count();
        $audit->record('social.account_removed', $account, old: ['network' => $account->network->value, 'name' => $account->displayName(), 'pending_posts' => $pending],
            organization: $organization, label: $account->displayName());
        $account->delete(); // its scheduled versions go with it; published history stays on the network

        $this->dispatch('toast', type: 'success', message: $account->displayName().' removed.'.($pending ? " {$pending} scheduled ".str('post')->plural($pending).' will no longer go to it.' : ''));
    }

    public function saveApproval(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization); // owners decide who approves
        $this->validate(['approvalMode' => ['required', Rule::in(array_keys(SocialApproval::MODES))]]);

        $profile = BusinessProfile::firstOrNew(['organization_id' => $organization->id]);
        $profile->social_approval = $this->approvalMode;
        $profile->save();
        $audit->changes('social.approval_changed', $profile, ['social_approval']);

        $this->dispatch('toast', type: 'success', message: 'Approval setting saved.');
    }

    private function account(string $ulid): SocialAccount
    {
        return SocialAccount::query()->forOrganization($this->organization())->where('ulid', $ulid)->firstOrFail();
    }

    public function render(SocialManager $social, Entitlements $entitlements): View
    {
        $organization = $this->organization();
        $accounts = SocialAccount::query()->forOrganization($organization)->orderBy('network')->orderBy('name')->get();

        return view('livewire.client.social.accounts', [
            'connectors' => $social->connectors(),
            'accounts' => $accounts,
            'canManage' => auth()->user()->can('social.manage', $organization),
            'canSetApproval' => auth()->user()->can('organization.update', $organization),
            'limit' => $entitlements->limit($organization, 'social_accounts'),
            'modes' => SocialApproval::MODES,
        ]);
    }
}
