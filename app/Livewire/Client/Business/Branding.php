<?php

namespace App\Livewire\Client\Business;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\BusinessProfile;
use App\Models\ChatWidget;
use App\Services\Billing\FeatureAccess;
use App\Services\Business\Branding as Brand;
use App\Support\Audit\Audit;
use App\Support\CustomerLocale;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Business › Branding (D82): white-label. The business's colour and sender name on everything its
 * customers see, no SureHelp lines, and optionally its own look in this portal. Needs the plan feature.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Branding')]
class Branding extends Component
{
    use ScopedToOrganization;

    public string $color = Brand::DEFAULT_COLOR;

    public string $sender = '';

    public bool $portal = true;

    public bool $chat = true;

    /** The language customers see (D92): en, es or auto. Any plan. */
    public string $locale = 'en';

    public function mount(): void
    {
        $this->authorize('organization.view', $this->organization());
        $profile = BusinessProfile::query()->forOrganization($this->organization())->first();
        $this->color = Brand::isColor($profile?->brand_color) ? (string) $profile->brand_color : Brand::DEFAULT_COLOR;
        $this->sender = (string) $profile?->email_sender_name;
        $this->portal = $profile === null || $profile->brand_portal;
        $this->locale = CustomerLocale::setting($this->organization());
    }

    public function saveLanguage(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);
        $this->validate(['locale' => ['required', 'in:'.implode(',', array_keys(CustomerLocale::CHOICES))]]);
        $profile = BusinessProfile::firstOrCreate(['organization_id' => $organization->id]);
        $profile->update(['customer_locale' => $this->locale]);
        $audit->changes('business_profile.updated', $profile, ['customer_locale']);
        $this->dispatch('toast', type: 'success', message: 'Saved. Your customers now see '.mb_strtolower(CustomerLocale::CHOICES[$this->locale]).'.');
    }

    public function save(Audit $audit, FeatureAccess $features, Brand $brand): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);
        abort_unless($features->allows($organization, 'white_label'), 403);
        $data = $this->validate([
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sender' => ['nullable', 'string', 'max:80', 'not_regex:/[<>@"\r\n]/'],
            'portal' => ['boolean'],
            'chat' => ['boolean'],
        ], ['color.regex' => 'Use a colour like #1d4ed8.', 'sender.not_regex' => 'Use a plain name, without an email address or symbols.']);

        $profile = BusinessProfile::firstOrCreate(['organization_id' => $organization->id]);
        $profile->update(['brand_color' => strtolower($data['color']), 'email_sender_name' => trim((string) $data['sender']) ?: null, 'brand_portal' => $data['portal']]);
        $audit->changes('business_profile.updated', $profile, ['brand_color', 'email_sender_name', 'brand_portal']);
        if ($data['chat']) {
            ChatWidget::query()->forOrganization($organization)->update(['color' => strtolower($data['color'])]);
        }
        $brand->forget($organization);

        // A full reload, so the portal picks up the new colour.
        session()->flash('success', 'Branding saved.');
        $this->redirectRoute('app.business.branding');
    }

    public function render(FeatureAccess $features, Brand $brand): View
    {
        $organization = $this->organization();

        return view('livewire.client.business.branding', [
            'allowed' => $features->allows($organization, 'white_label'),
            'brand' => $brand->for($organization),
            'previewColor' => Brand::isColor($this->color) ? $this->color : Brand::DEFAULT_COLOR,
            'canManage' => auth()->user()->can('settings.manage', $organization),
            'locales' => CustomerLocale::CHOICES,
        ]);
    }
}
