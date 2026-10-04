<?php

namespace App\Livewire\Client\Business;

use App\Enums\BusinessRuleType;
use App\Enums\EscalationPriority;
use App\Enums\EscalationType;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\BusinessRule;
use App\Models\BusinessService;
use App\Models\CallLog;
use App\Services\Rules\BusinessRules;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * How calls and bookings are handled for this business (spec §23), as data the system enforces.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Business rules')]
class Rules extends Component
{
    use ScopedToOrganization;

    public string $type = 'instruction';

    /** @var array<string, mixed> */
    public array $config = [];

    public function mount(): void
    {
        $this->authorize('organization.view', $this->organization());
        $this->resetConfig();
    }

    public function updatedType(): void
    {
        $this->resetValidation();
        $this->resetConfig();
    }

    private function resetConfig(): void
    {
        $this->config = [
            'text' => '', 'time' => '17:00', 'service_id' => '', 'min_notice_hours' => '', 'max_days_ahead' => '',
            'postal_codes' => '', 'field' => 'address', 'reasons' => [], 'escalation_type' => EscalationType::Complaint->value, 'priority' => '',
        ];
    }

    public function add(Audit $audit, BusinessRules $rules): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);
        $type = BusinessRuleType::tryFrom($this->type) ?? abort(422);

        $config = match ($type) {
            BusinessRuleType::Instruction => ['text' => trim($this->validate(['config.text' => ['required', 'string', 'max:500']], [], ['config.text' => 'instruction'])['config']['text'])],
            BusinessRuleType::BookingCutoff => (function () use ($organization) {
                $c = $this->validate([
                    'config.time' => ['required', 'date_format:H:i'],
                    'config.service_id' => ['nullable', Rule::exists('business_services', 'id')->where('organization_id', $organization->id)],
                ], [], ['config.time' => 'time', 'config.service_id' => 'service'])['config'];

                return ['time' => $c['time'], 'service_id' => filled($c['service_id'] ?? null) ? (int) $c['service_id'] : null];
            })(),
            BusinessRuleType::BookingWindow => (function () {
                $c = $this->validate([
                    'config.min_notice_hours' => ['nullable', 'numeric', 'min:0', 'max:720', 'required_without:config.max_days_ahead'],
                    'config.max_days_ahead' => ['nullable', 'integer', 'min:1', 'max:730'],
                ], ['config.min_notice_hours.required_without' => 'Set a notice period or a limit in days.'], ['config.min_notice_hours' => 'notice', 'config.max_days_ahead' => 'days ahead'])['config'];

                return array_filter([
                    'min_notice_minutes' => filled($c['min_notice_hours'] ?? null) ? (int) round((float) $c['min_notice_hours'] * 60) : null,
                    'max_days_ahead' => filled($c['max_days_ahead'] ?? null) ? (int) $c['max_days_ahead'] : null,
                ], fn ($v) => $v !== null);
            })(),
            BusinessRuleType::ServiceArea => (function () {
                $this->validate(['config.postal_codes' => ['required', 'string', 'max:5000']], [], ['config.postal_codes' => 'ZIP codes']);
                $codes = array_values(array_unique(preg_split('/[\s,;]+/', trim((string) $this->config['postal_codes']), -1, PREG_SPLIT_NO_EMPTY) ?: []));
                $bad = array_filter($codes, fn ($z) => ! preg_match('/^\d{5}$/', $z));
                if ($bad !== [] || $codes === []) {
                    $this->addError('config.postal_codes', 'Use 5-digit ZIP codes separated by commas or spaces'.($bad ? ' ('.implode(', ', array_slice($bad, 0, 3)).' isn\'t one)' : '').'.');

                    return null;
                }

                return ['postal_codes' => $codes];
            })(),
            BusinessRuleType::RequireDetail => ['field' => $this->validate(['config.field' => ['required', Rule::in(array_keys(BusinessRule::DETAILS))]])['config']['field']],
            BusinessRuleType::AutoEscalate => (function () {
                $c = $this->validate([
                    'config.reasons' => ['required', 'array', 'min:1'],
                    'config.reasons.*' => [Rule::in(array_keys(CallLog::REASONS))],
                    'config.escalation_type' => ['required', Rule::enum(EscalationType::class)],
                    'config.priority' => ['nullable', Rule::enum(EscalationPriority::class)],
                ], ['config.reasons.required' => 'Pick at least one kind of call.'], ['config.escalation_type' => 'escalation type'])['config'];

                return array_filter(['reasons' => array_values($c['reasons']), 'escalation_type' => $c['escalation_type'], 'priority' => $c['priority'] ?: null], fn ($v) => $v !== null);
            })(),
        };

        if ($config === null) {
            return;
        }

        $rule = BusinessRule::create(['organization_id' => $organization->id, 'type' => $type, 'config' => $config, 'created_by_user_id' => auth()->id()]);
        $audit->record('business_rule.created', $rule, new: ['type' => $type->value, 'config' => $config], organization: $organization, label: $type->label());

        $rules->forget();
        $this->resetConfig();
        $this->dispatch('toast', type: 'success', message: 'Rule added.');
    }

    public function toggle(int $id, Audit $audit, BusinessRules $rules): void
    {
        $this->authorize('settings.manage', $this->organization());
        $rule = $this->find($id);
        $rule->forceFill(['is_active' => ! $rule->is_active])->save();
        $audit->changes('business_rule.updated', $rule, ['is_active']);
        $rules->forget();
    }

    public function delete(int $id, Audit $audit, BusinessRules $rules): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);
        $rule = $this->find($id);
        $rule->delete();
        $audit->record('business_rule.deleted', $rule, old: ['type' => $rule->type->value, 'config' => $rule->config], organization: $organization, label: $rule->type->label());
        $rules->forget();
    }

    private function find(int $id): BusinessRule
    {
        return BusinessRule::query()->forOrganization($this->organization())->whereKey($id)->firstOrFail();
    }

    public function render(): View
    {
        $organization = $this->organization();
        $services = BusinessService::query()->forOrganization($organization)->withTrashed()->orderBy('name')->pluck('name', 'id');

        return view('livewire.client.business.rules', [
            'rules' => BusinessRule::query()->forOrganization($organization)->orderByDesc('is_active')
                ->orderByRaw("CASE type WHEN 'instruction' THEN 0 ELSE 1 END")->orderBy('id')->get(),
            'serviceNames' => $services->all(),
            'services' => BusinessService::query()->forOrganization($organization)->orderBy('name')->get(['id', 'name']),
            'types' => BusinessRuleType::cases(),
            'reasons' => CallLog::REASONS,
            'escalationTypes' => EscalationType::cases(),
            'priorities' => EscalationPriority::cases(),
            'details' => BusinessRule::DETAILS,
            'canManage' => auth()->user()->can('settings.manage', $organization),
        ]);
    }
}
