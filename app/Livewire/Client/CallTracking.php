<?php

namespace App\Livewire\Client;

use App\Exceptions\TelephonyUnavailable;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Services\Metrics\CallTrackingReport;
use App\Services\Telephony\TelephonyProvider;
use App\Support\Audit\Audit;
use App\Support\DemoGuard;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Call tracking (D114): a SureHelp number per marketing channel, answered like the main line, with
 * calls, booked jobs and cost per job for each channel. Owners add and remove numbers; anyone who
 * sees Results sees the report.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Call tracking')]
class CallTracking extends Component
{
    use ScopedToOrganization;

    public const MAX_NUMBERS = 10;

    /** Channels owners usually track; any name can be typed. */
    public const SUGGESTED = ['Google Ads', 'Google Local Services', 'Google Business Profile', 'Facebook / Instagram', 'Yelp', 'Website', 'Truck or yard signs', 'Mailers', 'Radio or TV', 'Referral partners'];

    #[Url]
    public string $month = '';

    public string $source = '';

    public string $areaCode = '';

    /** @var list<array{number: string, locality?: string|null, region?: string|null}> */
    public array $found = [];

    /** @var array<string, string> spend typed per line ulid */
    public array $spend = [];

    public function mount(): void
    {
        $this->authorize('reports.view', $this->organization());
        foreach ($this->lines() as $line) {
            $this->spend[$line->ulid] = Money::toInput($line->monthly_spend_cents);
        }
    }

    public function search(TelephonyProvider $provider): void
    {
        $this->authorizeManage();
        $this->validate([
            'source' => ['required', 'string', 'max:60'],
            'areaCode' => ['required', 'digits:3'],
        ], ['areaCode.digits' => 'Enter a 3-digit area code.', 'source.required' => 'Name the channel this number is for.']);
        try {
            $this->found = array_slice($provider->availableNumbers($this->areaCode), 0, 8);
        } catch (TelephonyUnavailable $e) {
            $this->addError('areaCode', $e->getMessage());

            return;
        }
        if ($this->found === []) {
            $this->addError('areaCode', 'No numbers free in that area code. Try a nearby one.');
        }
    }

    public function buy(string $number, TelephonyProvider $provider, Audit $audit): void
    {
        $organization = $this->authorizeManage();
        abort_unless(collect($this->found)->contains('number', $number), 422);
        $this->validate(['source' => ['required', 'string', 'max:60']]);
        $main = PhoneNumber::query()->forOrganization($organization)->active()->whereNull('tracking_source')->orderBy('id')->first();
        if (! $main) {
            throw ValidationException::withMessages(['source' => 'Your SureHelp phone line needs to be set up first.']);
        }
        if (PhoneNumber::query()->forOrganization($organization)->active()->whereNotNull('tracking_source')->count() >= self::MAX_NUMBERS) {
            throw ValidationException::withMessages(['source' => 'You have '.self::MAX_NUMBERS.' tracking numbers, the most allowed. Remove one you no longer use.']);
        }
        if (DemoGuard::isDemo($organization->id)) {
            throw ValidationException::withMessages(['source' => 'Demo businesses can\'t buy real phone numbers.']);
        }
        try {
            $bought = $provider->buyNumber($number, ['voice' => route('webhooks.voice.incoming'), 'status' => route('webhooks.voice.status'), 'sms' => route('webhooks.sms.incoming')]);
        } catch (TelephonyUnavailable $e) {
            $this->addError('areaCode', $e->getMessage());

            return;
        }
        // Answered exactly like the main line: same transfer number, recording, after-hours and text-back.
        $line = PhoneNumber::create([
            'organization_id' => $organization->id, 'provider' => $provider->name(), 'provider_sid' => $bought['sid'], 'number' => $bought['number'],
            'label' => trim($this->source), 'tracking_source' => trim($this->source),
            'transfer_number' => $main->transfer_number, 'record_calls' => $main->record_calls, 'after_hours' => $main->after_hours,
            'text_back' => $main->text_back, 'text_back_message' => $main->text_back_message, 'status' => PhoneNumber::STATUS_ACTIVE,
        ]);
        $audit->record('call_tracking.number_added', $line, new: ['number' => $line->number, 'source' => $line->tracking_source], label: $line->tracking_source.' '.$line->display());
        $this->spend[$line->ulid] = '';
        $this->reset(['source', 'areaCode', 'found']);
        $this->dispatch('toast', type: 'success', message: $line->display().' is ready. Put it on your '.$line->tracking_source.' ads.');
    }

    public function saveSpend(string $ulid, Audit $audit): void
    {
        $this->authorizeManage();
        $line = $this->line($ulid);
        try {
            $cents = filled($this->spend[$ulid] ?? '') ? Money::parse((string) $this->spend[$ulid]) : null;
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['spend.'.$ulid => 'Enter an amount like 500 or 500.00.']);
        }
        $old = $line->monthly_spend_cents;
        $line->forceFill(['monthly_spend_cents' => $cents])->save();
        if ($old !== $cents) {
            $audit->record('call_tracking.spend_updated', $line, old: ['monthly_spend_cents' => $old], new: ['monthly_spend_cents' => $cents], label: $line->tracking_source ?: 'Main number');
        }
        $this->dispatch('toast', type: 'success', message: 'Saved.');
    }

    public function remove(string $ulid, TelephonyProvider $provider, Audit $audit): void
    {
        $this->authorizeManage();
        $line = $this->line($ulid);
        abort_unless($line->isTracking() && $line->status === PhoneNumber::STATUS_ACTIVE, 404);
        try {
            if ($line->provider_sid && $line->provider === $provider->name()) {
                $provider->releaseNumber($line->provider_sid);
            }
        } catch (TelephonyUnavailable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }
        $line->forceFill(['status' => PhoneNumber::STATUS_RELEASED, 'released_at' => now()])->save();
        $audit->record('call_tracking.number_removed', $line, old: ['status' => 'active'], new: ['status' => 'released'], label: $line->tracking_source.' '.$line->display());
        $this->dispatch('toast', type: 'success', message: $line->display().' was removed. Its past calls stay in the report.');
    }

    private function authorizeManage(): Organization
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);

        return $organization;
    }

    private function line(string $ulid): PhoneNumber
    {
        return PhoneNumber::query()->forOrganization($this->organization())->where('ulid', $ulid)->first() ?? abort(404);
    }

    /** @return Collection<int, PhoneNumber> */
    private function lines(): Collection
    {
        return PhoneNumber::query()->forOrganization($this->organization())->active()->get();
    }

    public function render(CallTrackingReport $report): View
    {
        $organization = $this->organization();
        $zone = $organization->timezoneOrDefault();
        $months = collect(range(0, 11))->map(fn (int $i) => CarbonImmutable::now($zone)->startOfMonth()->subMonths($i));
        $start = $months->first(fn (CarbonImmutable $m) => $m->format('Y-m') === $this->month) ?? $months->first();

        return view('livewire.client.call-tracking', [
            'rows' => $report->compute($organization, $start, $start->endOfMonth()),
            'months' => $months,
            'current' => $start,
            'canManage' => auth()->user()->can('organization.update', $organization),
            'hasMainLine' => PhoneNumber::query()->forOrganization($organization)->active()->whereNull('tracking_source')->exists(),
            'trackingCount' => PhoneNumber::query()->forOrganization($organization)->active()->whereNotNull('tracking_source')->count(),
            'suggested' => self::SUGGESTED,
        ]);
    }
}
