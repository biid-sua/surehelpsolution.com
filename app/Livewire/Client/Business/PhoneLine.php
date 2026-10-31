<?php

namespace App\Livewire\Client\Business;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\PhoneNumber;
use App\Models\VoiceCall;
use App\Services\Telephony\VoiceStats;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Business › Phone line (D61): the business's SureHelp number, how to forward its own phone there,
 * where calls go when nobody can answer or the business is closed, and how its calls are answered.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Phone line')]
class PhoneLine extends Component
{
    use ScopedToOrganization;

    public string $transferNumber = '';

    public string $afterHours = 'agents';

    public bool $recordCalls = true;

    public bool $textBack = false;

    public string $textBackMessage = '';

    public function mount(): void
    {
        $this->authorize('organization.view', $this->organization());
        if ($line = $this->line()) {
            $this->transferNumber = $line->transfer_number ? PhoneNumber::pretty($line->transfer_number) : '';
            $this->afterHours = $line->after_hours;
            $this->recordCalls = $line->record_calls;
            $this->textBack = $line->text_back;
            $this->textBackMessage = (string) $line->text_back_message;
        }
    }

    private function line(): ?PhoneNumber
    {
        return PhoneNumber::query()->forOrganization($this->organization())->active()->whereNull('tracking_source')->orderBy('id')->first();
    }

    public function save(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);
        $line = $this->line();
        abort_unless($line !== null, 404);
        $this->validate([
            'transferNumber' => ['nullable', 'string', 'max:30'],
            'afterHours' => ['required', Rule::in(array_keys(PhoneNumber::AFTER_HOURS))],
            'textBackMessage' => ['nullable', 'string', 'max:320'],
        ]);
        $transfer = trim($this->transferNumber) === '' ? null : PhoneNumber::normalize($this->transferNumber);
        if (trim($this->transferNumber) !== '' && $transfer === null) {
            $this->addError('transferNumber', 'Enter a full phone number, e.g. (512) 555-0100.');

            return;
        }
        if ($transfer === $line->number) {
            $this->addError('transferNumber', 'Use your own phone here, not the SureHelp number.');

            return;
        }

        $fields = ['transfer_number', 'after_hours', 'record_calls', 'text_back', 'text_back_message'];
        $old = $line->only($fields);
        $settings = ['transfer_number' => $transfer, 'after_hours' => $this->afterHours, 'record_calls' => $this->recordCalls,
            'text_back' => $this->textBack, 'text_back_message' => trim($this->textBackMessage) ?: null];
        $line->forceFill($settings)->save();
        // Call tracking numbers (D114) are answered exactly like the main line.
        PhoneNumber::query()->forOrganization($this->organization())->active()->whereNotNull('tracking_source')->get()
            ->each(fn (PhoneNumber $tracking) => $tracking->forceFill($settings)->save());
        $audit->record('phone_line.updated', $line, $old, $line->only($fields), organization: $organization, label: $line->display());
        $this->dispatch('toast', type: 'success', message: 'Phone line settings saved.');
    }

    public function render(VoiceStats $stats): View
    {
        $organization = $this->organization();
        $line = $this->line();

        return view('livewire.client.business.phone-line', [
            'line' => $line,
            'canManage' => auth()->user()->can('settings.manage', $organization),
            'stats' => $stats->summary($organization->id, now()->subDays(30), now()),
            'calls' => VoiceCall::query()->forOrganization($organization)->with(['callLog:id,call_id', 'answeredBy:id,name', 'placedBy:id,name', 'customer'])
                ->latest('started_at')->limit(20)->get(),
            'timezone' => $organization->timezoneOrDefault(),
            'smsReady' => (bool) config('telephony.sms'),
        ]);
    }
}
