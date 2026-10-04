<?php

namespace App\Livewire\Client\Business;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\BusinessLocation;
use App\Models\BusinessProfile;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Business profile and primary location (spec §9). What agents and, later, the AI read about the business.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Business profile')]
class Profile extends Component
{
    use ScopedToOrganization;

    /** US business timezones first; any IANA zone accepted. */
    public const TIMEZONES = [
        'America/New_York' => 'Eastern (New York)',
        'America/Chicago' => 'Central (Chicago)',
        'America/Denver' => 'Mountain (Denver)',
        'America/Phoenix' => 'Mountain, no DST (Phoenix)',
        'America/Los_Angeles' => 'Pacific (Los Angeles)',
        'America/Anchorage' => 'Alaska (Anchorage)',
        'Pacific/Honolulu' => 'Hawaii (Honolulu)',
    ];

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        $organization = $this->organization();
        $this->authorize('organization.view', $organization);

        $profile = BusinessProfile::firstOrNew(['organization_id' => $organization->id]);
        $location = $organization->primaryLocation()->first();

        $this->form = [
            'name' => $organization->name,
            'timezone' => (string) $organization->timezone,
            'legal_name' => (string) $profile->legal_name,
            'description' => (string) $profile->description,
            'business_type' => (string) $profile->business_type,
            'industry' => (string) $profile->industry,
            'website' => (string) $profile->website,
            'phone' => (string) $profile->phone,
            'email' => (string) $profile->email,
            'service_area' => (string) $profile->service_area,
            'address_line1' => (string) $location?->address_line1,
            'address_line2' => (string) $location?->address_line2,
            'city' => (string) $location?->city,
            'state' => (string) $location?->state,
            'postal_code' => (string) $location?->postal_code,
        ];
    }

    public function save(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);

        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.timezone' => ['required', 'timezone:all'],
            'form.legal_name' => ['nullable', 'string', 'max:255'],
            'form.description' => ['nullable', 'string', 'max:2000'],
            'form.business_type' => ['nullable', 'string', 'max:100'],
            'form.industry' => ['nullable', 'string', 'max:100'],
            'form.website' => ['nullable', 'url:http,https', 'max:255'],
            'form.phone' => ['nullable', 'string', 'max:40'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.service_area' => ['nullable', 'string', 'max:2000'],
            'form.address_line1' => ['nullable', 'string', 'max:255'],
            'form.address_line2' => ['nullable', 'string', 'max:255'],
            'form.city' => ['nullable', 'string', 'max:100'],
            'form.state' => ['nullable', 'string', 'max:100'],
            'form.postal_code' => ['nullable', 'string', 'max:20'],
        ], attributes: [
            'form.name' => 'business name',
            'form.timezone' => 'timezone',
            'form.website' => 'website',
            'form.email' => 'email',
        ])['form'];

        $nullable = fn (string $key) => filled($data[$key] ?? null) ? trim((string) $data[$key]) : null;

        DB::transaction(function () use ($organization, $data, $nullable, $audit) {
            $organization->update(['name' => trim($data['name']), 'timezone' => $data['timezone']]);
            $audit->changes('organization.updated', $organization, ['name', 'timezone']);

            $profile = BusinessProfile::firstOrNew(['organization_id' => $organization->id]);
            $profile->fill([
                'legal_name' => $nullable('legal_name'),
                'description' => $nullable('description'),
                'business_type' => $nullable('business_type'),
                'industry' => $nullable('industry'),
                'website' => $nullable('website'),
                'phone' => $nullable('phone'),
                'email' => $nullable('email'),
                'service_area' => $nullable('service_area'),
            ])->save();
            $audit->changes('business_profile.updated', $profile);

            $location = $organization->primaryLocation()->first()
                ?? new BusinessLocation(['organization_id' => $organization->id, 'name' => 'Main location', 'is_primary' => true]);
            $location->fill([
                'address_line1' => $nullable('address_line1'),
                'address_line2' => $nullable('address_line2'),
                'city' => $nullable('city'),
                'state' => $nullable('state'),
                'postal_code' => $nullable('postal_code'),
            ])->save();
        });

        $this->dispatch('toast', type: 'success', message: 'Business profile saved.');
    }

    public function render(): View
    {
        $organization = $this->organization();

        return view('livewire.client.business.profile', [
            'organization' => $organization,
            'canEdit' => auth()->user()->can('organization.update', $organization),
            'timezones' => self::TIMEZONES,
            'otherTimezones' => array_values(array_diff(\DateTimeZone::listIdentifiers(), array_keys(self::TIMEZONES))),
        ]);
    }
}
