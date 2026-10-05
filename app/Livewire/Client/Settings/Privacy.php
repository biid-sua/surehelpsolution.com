<?php

namespace App\Livewire\Client\Settings;

use App\Jobs\BuildDataExport;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\DataExport;
use App\Services\Privacy\AccountClosure;
use App\Services\Privacy\Retention;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Data and privacy for the business owner (spec §56–57, §91; task.md CMP-05, CMP-07): download
 * everything, choose how long history is kept, close the account.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Data & privacy')]
class Privacy extends Component
{
    use ScopedToOrganization;

    /** Months as a string for the select; '' keeps everything. */
    public string $retention = '';

    public string $confirmName = '';

    public string $password = '';

    public function mount(): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        $this->retention = (string) $organization->retention_months;
    }

    public function requestExport(): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);

        $recent = DataExport::query()->where('organization_id', $organization->id)->where('created_at', '>=', now()->subDay());
        if ((clone $recent)->where('status', DataExport::PENDING)->exists()) {
            $this->dispatch('toast', type: 'info', message: 'An export is already being prepared.');

            return;
        }
        if ($recent->count() >= 3) {
            $this->dispatch('toast', type: 'warning', message: 'You can make three exports a day. Try again tomorrow.');

            return;
        }

        $export = DataExport::create(['organization_id' => $organization->id, 'requested_by' => auth()->id()]);
        app(Audit::class)->record('data_export.requested', $export, organization: $organization);
        BuildDataExport::dispatch($export);

        $this->dispatch('toast', type: 'success', message: 'We\'re preparing your export. We\'ll email you when it\'s ready.');
    }

    public function saveRetention(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        $this->validate(['retention' => ['nullable', Rule::in(array_map('strval', Retention::CHOICES))]]);

        $organization->forceFill(['retention_months' => $this->retention === '' ? null : (int) $this->retention])->save();
        $audit->changes('organization.retention_changed', $organization, ['retention_months']);
        $this->dispatch('toast', type: 'success', message: 'Saved. '.Retention::label($organization->retention_months).'.');
    }

    public function closeAccount(AccountClosure $closure): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        abort_unless($organization->owner_user_id === auth()->id(), 403, 'Only the owner can close the account.');
        $this->validate(['confirmName' => ['required', 'string'], 'password' => ['required', 'string']], attributes: ['confirmName' => 'business name']);

        // The password check must not become a way to guess passwords.
        $key = 'close-account:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => 'Too many tries. Wait '.ceil(RateLimiter::availableIn($key) / 60).' minutes.']);
        }
        RateLimiter::hit($key, 600);

        $closure->request($organization, auth()->user(), $this->confirmName, $this->password);
        RateLimiter::clear($key);
        $this->reset('confirmName', 'password');
        $this->dispatch('toast', type: 'success', message: 'Your account will close on '.$organization->closes_at->format('M j').'. We\'ve emailed you the details.');
    }

    public function keepAccount(AccountClosure $closure): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        $closure->cancel($organization, auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Your account stays open. Nothing was deleted.');
    }

    public function render(): View
    {
        $organization = $this->organization();

        return view('livewire.client.settings.privacy', [
            'organization' => $organization,
            'exports' => DataExport::query()->where('organization_id', $organization->id)->whereNot('status', DataExport::EXPIRED)
                ->with('requester:id,name')->latest()->limit(5)->get(),
            'choices' => array_combine(array_map('strval', Retention::CHOICES), array_map([Retention::class, 'label'], Retention::CHOICES)) + ['' => Retention::label(null)],
            'isOwner' => $organization->owner_user_id === auth()->id(),
            'timezone' => $organization->timezoneOrDefault(),
        ]);
    }
}
