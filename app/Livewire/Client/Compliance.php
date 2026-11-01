<?php

namespace App\Livewire\Client;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Customer;
use App\Models\SmsOptOut;
use App\Services\Compliance\Hipaa;
use App\Services\Telephony\Texting;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Compliance (D117): HIPAA mode for healthcare businesses, and texting and email consent at a glance,
 * with the records to download.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Compliance')]
class Compliance extends Component
{
    use ScopedToOrganization;

    public bool $agree = false;

    public function mount(): void
    {
        $this->authorize('organization.view', $this->organization());
    }

    public function enableHipaa(Hipaa $hipaa): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        $this->resetErrorBag();
        if (! $this->agree) {
            throw ValidationException::withMessages(['agree' => 'Tick the box to sign the Business Associate Agreement for your business.']);
        }
        $hipaa->enable($organization, auth()->user(), (string) request()->ip(), request()->userAgent());
        $this->agree = false;
        $this->dispatch('toast', type: 'success', message: 'HIPAA mode is on.');
    }

    public function disableHipaa(Hipaa $hipaa): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        $hipaa->disable($organization, auth()->user());
        $this->dispatch('toast', type: 'success', message: 'HIPAA mode is off.');
    }

    public function render(Texting $texting): View
    {
        $organization = $this->organization()->refresh();
        $customers = Customer::query()->forOrganization($organization);

        return view('livewire.client.compliance', [
            'organization' => $organization,
            'canManage' => auth()->user()->can('organization.update', $organization),
            'canExport' => auth()->user()->can('customers.view', $organization),
            'textingOn' => $texting->enabled() && $texting->lineFor($organization) !== null,
            'stats' => [
                'customers' => (clone $customers)->count(),
                'texts' => (clone $customers)->where('sms_consent', true)->count(),
                'stop' => SmsOptOut::query()->forOrganization($organization)->count(),
                'unsubscribed' => (clone $customers)->whereNotNull('marketing_opt_out_at')->count(),
            ],
        ]);
    }
}
