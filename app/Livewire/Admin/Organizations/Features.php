<?php

namespace App\Livewire\Admin\Organizations;

use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Organization;
use App\Services\Billing\FeatureAccess;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Switch a paid feature on or off for one business, whatever its plan (FND-18, D46).
 * Shown on the admin business page.
 */
class Features extends Component
{
    use PlatformAdminOnly;

    #[Locked]
    public int $organizationId;

    /** @var array<string, string> feature => plan | on | off */
    public array $choices = [];

    public function mount(int $organizationId, FeatureAccess $features): void
    {
        $this->organizationId = $organizationId;
        $organization = $this->organization();
        $this->authorize('billing.view');
        $overrides = $features->overrides($organization);
        foreach (array_keys($features->catalog()) as $key) {
            $this->choices[$key] = array_key_exists($key, $overrides) ? ($overrides[$key] ? 'on' : 'off') : 'plan';
        }
    }

    private function organization(): Organization
    {
        return Organization::findOrFail($this->organizationId);
    }

    public function save(FeatureAccess $features, Audit $audit): void
    {
        $this->authorize('billing.manage');
        $organization = $this->organization();
        $before = $features->overrides($organization);

        DB::transaction(function () use ($features, $organization) {
            foreach (array_keys($features->catalog()) as $key) {
                $choice = $this->choices[$key] ?? 'plan';
                if ($choice === 'plan') {
                    DB::table('organization_features')->where('organization_id', $organization->id)->where('feature', $key)->delete();
                } else {
                    DB::table('organization_features')->updateOrInsert(
                        ['organization_id' => $organization->id, 'feature' => $key],
                        ['enabled' => $choice === 'on', 'set_by' => auth()->id(), 'updated_at' => now(), 'created_at' => now()],
                    );
                }
            }
        });

        $features->forget($organization);
        $audit->record('organization.features_changed', $organization, $before, $features->overrides($organization), label: $organization->name);
        $this->dispatch('toast', type: 'success', message: 'Features saved for '.$organization->name.'.');
    }

    public function render(FeatureAccess $features): View
    {
        $organization = $this->organization();

        return view('livewire.admin.organizations.features', [
            'catalog' => $features->catalog(),
            'effective' => collect(array_keys($features->catalog()))->mapWithKeys(fn ($k) => [$k => $features->allows($organization, $k)])->all(),
            'modes' => $features->modes(),
            'canManage' => auth()->user()->hasPermissionIn('billing.manage'),
        ]);
    }
}
