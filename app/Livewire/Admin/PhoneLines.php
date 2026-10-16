<?php

namespace App\Livewire\Admin;

use App\Exceptions\TelephonyUnavailable;
use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\VoiceCall;
use App\Services\Telephony\TelephonyProvider;
use App\Services\Telephony\VoiceStats;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin › Phone lines (D60): each business's SureHelp number, where calls go when no agent can
 * answer, whether calls are recorded, and how calls are being answered.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Phone lines')]
class PhoneLines extends Component
{
    use PlatformAdminOnly;

    public string $organization = '';

    public string $mode = 'buy';

    public string $areaCode = '';

    /** @var list<array{number: string, locality: ?string, region: ?string}> */
    public array $found = [];

    public string $number = '';

    public string $sid = '';

    public string $label = '';

    public string $transferNumber = '';

    public bool $recordCalls = true;

    public ?string $editing = null;

    public string $editLabel = '';

    public string $editTransfer = '';

    public bool $editRecord = true;

    public string $editAfterHours = 'agents';

    public bool $editTextBack = false;

    public string $editTextBackMessage = '';

    public int $days = 7;

    public function mount(): void
    {
        $this->authorize('settings.view');
    }

    public function search(TelephonyProvider $provider): void
    {
        $this->authorize('settings.manage');
        $this->validate(['areaCode' => ['required', 'digits:3']], ['areaCode.digits' => 'Enter a 3-digit area code.']);
        try {
            $this->found = $provider->availableNumbers($this->areaCode);
            $this->number = '';
            if ($this->found === []) {
                $this->addError('areaCode', 'No numbers free in that area code. Try a nearby one.');
            }
        } catch (TelephonyUnavailable $e) {
            $this->addError('areaCode', $e->getMessage());
        }
    }

    public function add(TelephonyProvider $provider, Audit $audit): void
    {
        $this->authorize('settings.manage');
        $this->validate([
            'organization' => ['required', Rule::exists('organizations', 'ulid')],
            'mode' => ['required', Rule::in(['buy', 'connect'])],
            'number' => ['exclude_unless:mode,buy', 'required', 'string'],
            'sid' => ['exclude_unless:mode,connect', 'required', 'regex:/^PN[0-9a-zA-Z]{8,40}$/'],
            'label' => ['nullable', 'string', 'max:80'],
            'transferNumber' => ['nullable', 'string', 'max:30'],
        ], ['number.required' => 'Pick a number.', 'sid.regex' => 'A number SID starts with PN.'], ['organization' => 'business', 'sid' => 'number SID']);

        $transfer = $this->transferOrFail($this->transferNumber, 'transferNumber');
        if ($transfer === false) {
            return;
        }
        $organization = Organization::query()->where('ulid', $this->organization)->firstOrFail();
        $urls = $this->urls();

        try {
            if ($this->mode === 'buy') {
                abort_unless(collect($this->found)->contains('number', $this->number), 422);
                $bought = $provider->buyNumber($this->number, $urls);
            } else {
                $bought = ['sid' => $this->sid, 'number' => $provider->connectNumber($this->sid, $urls)];
            }
        } catch (TelephonyUnavailable $e) {
            $this->addError($this->mode === 'buy' ? 'number' : 'sid', $e->getMessage());

            return;
        }

        $existing = PhoneNumber::withoutGlobalScopes()->where('number', $bought['number'])->first();
        if ($existing && $existing->status === PhoneNumber::STATUS_ACTIVE) {
            $this->addError('sid', 'That number is already a line for '.($existing->organization->name ?? 'another business').'.');

            return;
        }
        $line = $existing ?? new PhoneNumber;
        $line->forceFill([
            'organization_id' => $organization->id, 'provider' => $provider->name(), 'provider_sid' => $bought['sid'], 'number' => $bought['number'],
            'label' => $this->label ?: null, 'transfer_number' => $transfer, 'record_calls' => $this->recordCalls,
            'status' => PhoneNumber::STATUS_ACTIVE, 'released_at' => null,
        ])->save();
        $audit->record('phone_line.added', $line, new: ['number' => $line->number, 'transfer_number' => $transfer, 'record_calls' => $line->record_calls], organization: $organization, label: $line->display());

        $this->reset(['areaCode', 'found', 'number', 'sid', 'label', 'transferNumber']);
        $this->recordCalls = true;
        $this->dispatch('toast', type: 'success', message: $line->display().' now rings '.$organization->name.'\'s agents.');
    }

    public function edit(string $ulid): void
    {
        $line = $this->line($ulid);
        $this->editing = $line->ulid;
        $this->editLabel = (string) $line->label;
        $this->editTransfer = (string) ($line->transfer_number ? PhoneNumber::pretty($line->transfer_number) : '');
        $this->editRecord = $line->record_calls;
        $this->editAfterHours = $line->after_hours;
        $this->editTextBack = $line->text_back;
        $this->editTextBackMessage = (string) $line->text_back_message;
        $this->resetValidation();
    }

    public function update(Audit $audit): void
    {
        $this->authorize('settings.manage');
        $line = $this->line((string) $this->editing);
        $this->validate([
            'editLabel' => ['nullable', 'string', 'max:80'], 'editTransfer' => ['nullable', 'string', 'max:30'],
            'editAfterHours' => ['required', Rule::in(array_keys(PhoneNumber::AFTER_HOURS))], 'editTextBackMessage' => ['nullable', 'string', 'max:320'],
        ]);
        $transfer = $this->transferOrFail($this->editTransfer, 'editTransfer');
        if ($transfer === false) {
            return;
        }
        $fields = ['label', 'transfer_number', 'record_calls', 'after_hours', 'text_back', 'text_back_message'];
        $old = $line->only($fields);
        $line->forceFill(['label' => $this->editLabel ?: null, 'transfer_number' => $transfer, 'record_calls' => $this->editRecord,
            'after_hours' => $this->editAfterHours, 'text_back' => $this->editTextBack, 'text_back_message' => trim($this->editTextBackMessage) ?: null])->save();
        $audit->record('phone_line.updated', $line, $old, $line->only($fields), organization: $line->organization, label: $line->display());
        $this->editing = null;
        $this->dispatch('toast', type: 'success', message: 'Line saved.');
    }

    public function release(string $ulid, TelephonyProvider $provider, Audit $audit): void
    {
        $this->authorize('settings.manage');
        $line = $this->line($ulid);
        try {
            if ($line->provider_sid && $line->provider === $provider->name()) {
                $provider->releaseNumber($line->provider_sid);
            }
        } catch (TelephonyUnavailable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }
        $line->forceFill(['status' => PhoneNumber::STATUS_RELEASED, 'released_at' => now()])->save();
        $audit->record('phone_line.released', $line, ['status' => 'active'], ['status' => 'released'], organization: $line->organization, label: $line->display());
        $this->dispatch('toast', type: 'success', message: $line->display().' was released. Calls to it no longer reach SureHelp.');
    }

    /** @return array{voice: string, status: string, sms: string} */
    private function urls(): array
    {
        return ['voice' => route('webhooks.voice.incoming'), 'status' => route('webhooks.voice.status'), 'sms' => route('webhooks.sms.incoming')];
    }

    /** E.164, null for blank, false (with an error) when it can't be read. */
    private function transferOrFail(string $value, string $field): string|false|null
    {
        if (trim($value) === '') {
            return null;
        }
        $e164 = PhoneNumber::normalize($value);
        if ($e164 === null) {
            $this->addError($field, 'Enter a full phone number, e.g. (512) 555-0100.');

            return false;
        }

        return $e164;
    }

    private function line(string $ulid): PhoneNumber
    {
        return PhoneNumber::withoutGlobalScopes()->where('ulid', $ulid)->firstOrFail();
    }

    public function render(TelephonyProvider $provider, VoiceStats $stats): View
    {
        $from = now()->subDays(in_array($this->days, [1, 7, 30], true) ? $this->days : 7);
        $lines = PhoneNumber::withoutGlobalScopes()->with('organization:id,name,ulid')->orderByRaw("status = 'active' desc")->orderBy('number')->get();

        return view('livewire.admin.phone-lines', [
            'provider' => $provider,
            'canManage' => auth()->user()->can('settings.manage'),
            'urls' => $this->urls(),
            'lines' => $lines,
            'lineStats' => $lines->mapWithKeys(fn (PhoneNumber $l) => [$l->id => $stats->summary($l->organization_id, $from, now())]),
            'overall' => $stats->summary(null, $from, now()),
            'businesses' => Organization::query()->orderBy('name')->get(['ulid', 'name']),
            'recent' => VoiceCall::withoutGlobalScopes()->with(['answeredBy:id,name', 'placedBy:id,name', 'organization:id,name'])->latest('started_at')->limit(15)->get(),
        ]);
    }
}
