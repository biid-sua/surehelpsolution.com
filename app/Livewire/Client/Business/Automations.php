<?php

namespace App\Livewire\Client\Business;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\BusinessService;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Business › Automations (spec §42–43, D50): "when this happens, wait, then do that", from ready-made
 * recipes or from scratch, with each run's history.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Automations')]
class Automations extends Component
{
    use ScopedToOrganization;

    public const UNITS = ['minutes' => 1, 'hours' => 60, 'days' => 1440];

    /** Ready-made starting points. */
    public const RECIPES = [
        'review' => [
            'name' => 'Thank you and review request',
            'trigger' => 'appointment_completed', 'delay' => 2, 'unit' => 'hours', 'action' => 'send_email',
            'config' => ['subject' => 'Thank you from {business}', 'consent_only' => true, 'review_url' => '',
                'body' => "Hi {first_name},\n\nThank you for choosing {business} for your {service}. We hope everything went well.\n\nIf you have a minute, a short review helps us a lot:\n{review_link}\n\nThank you,\n{business}"],
        ],
        'rebook' => [
            'name' => 'Rebook cancelled appointments',
            'trigger' => 'appointment_cancelled', 'delay' => 1, 'unit' => 'days', 'action' => 'create_task',
            'config' => ['title' => 'Call {first_name} to rebook {service}', 'due_hours' => 24],
        ],
        'lead' => [
            'name' => 'Tell the team about new leads',
            'trigger' => 'customer_created', 'delay' => 0, 'unit' => 'minutes', 'action' => 'notify_team',
            'config' => ['message' => 'New lead: {first_name}. Give them a call while they\'re interested.'],
        ],
    ];

    public ?string $editing = null;   // ulid, or "new"

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        $this->authorize('organization.view', $this->organization());
    }

    public function start(string $recipe = ''): void
    {
        $this->authorize('settings.manage', $this->organization());
        $r = self::RECIPES[$recipe] ?? ['name' => '', 'trigger' => 'appointment_completed', 'delay' => 0, 'unit' => 'hours', 'action' => 'send_email', 'config' => ['subject' => '', 'body' => '', 'consent_only' => true, 'review_url' => '']];
        $this->form = ['name' => $r['name'], 'trigger' => $r['trigger'], 'delay' => (string) $r['delay'], 'unit' => $r['unit'], 'action' => $r['action'],
            'service_ids' => [], 'is_active' => true] + $r['config'] + $this->blankConfig();
        $this->resetValidation();
        $this->editing = 'new';
    }

    public function edit(string $ulid): void
    {
        $this->authorize('settings.manage', $this->organization());
        $a = $this->find($ulid);
        [$delay, $unit] = $a->delay_minutes % 1440 === 0 && $a->delay_minutes > 0 ? [$a->delay_minutes / 1440, 'days']
            : ($a->delay_minutes % 60 === 0 && $a->delay_minutes > 0 ? [$a->delay_minutes / 60, 'hours'] : [$a->delay_minutes, 'minutes']);
        $this->form = ['name' => $a->name, 'trigger' => $a->trigger, 'delay' => (string) $delay, 'unit' => $unit, 'action' => $a->action,
            'service_ids' => array_map('strval', (array) $a->condition('service_ids', [])), 'is_active' => $a->is_active] + ($a->action_config ?? []) + $this->blankConfig();
        $this->resetValidation();
        $this->editing = $a->ulid;
    }

    /** @return array<string, mixed> */
    private function blankConfig(): array
    {
        return ['subject' => '', 'body' => '', 'consent_only' => true, 'review_url' => '', 'title' => '', 'due_hours' => '24', 'message' => ''];
    }

    public function save(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);
        $action = $this->form['action'] ?? '';
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:120'],
            'form.trigger' => ['required', Rule::in(array_keys(Automation::TRIGGERS))],
            'form.delay' => ['required', 'integer', 'min:0', 'max:43200'],
            'form.unit' => ['required', Rule::in(array_keys(self::UNITS))],
            'form.action' => ['required', Rule::in(array_keys(Automation::ACTIONS))],
            'form.service_ids' => ['array'],
            'form.service_ids.*' => ['integer', Rule::exists('business_services', 'id')->where('organization_id', $organization->id)],
            'form.is_active' => ['boolean'],
            'form.subject' => [Rule::requiredIf($action === 'send_email'), 'nullable', 'string', 'max:200'],
            'form.body' => [Rule::requiredIf($action === 'send_email'), 'nullable', 'string', 'max:5000'],
            'form.consent_only' => ['boolean'],
            'form.review_url' => [Rule::requiredIf($action === 'send_email' && str_contains((string) ($this->form['body'] ?? ''), '{review_link}')), 'nullable', 'url:https', 'max:500'],
            'form.title' => [Rule::requiredIf($action === 'create_task'), 'nullable', 'string', 'max:200'],
            'form.due_hours' => [Rule::requiredIf($action === 'create_task'), 'nullable', 'integer', 'min:0', 'max:720'],
            'form.message' => [Rule::requiredIf($action === 'notify_team'), 'nullable', 'string', 'max:500'],
        ], attributes: ['form.review_url' => 'review link', 'form.body' => 'message', 'form.due_hours' => 'due time'])['form'];

        $minutes = (int) $data['delay'] * self::UNITS[$data['unit']];
        if ($minutes > Automation::MAX_DELAY_MINUTES) {
            $this->addError('form.delay', 'Wait at most 30 days.');

            return;
        }

        $config = match ($data['action']) {
            'send_email' => ['subject' => trim((string) $data['subject']), 'body' => trim((string) $data['body']), 'consent_only' => (bool) ($data['consent_only'] ?? true), 'review_url' => trim((string) ($data['review_url'] ?? ''))],
            'create_task' => ['title' => trim((string) $data['title']), 'due_hours' => (int) $data['due_hours']],
            default => ['message' => trim((string) $data['message'])],
        };
        $appointmentTrigger = str_starts_with($data['trigger'], 'appointment_');
        $values = [
            'name' => trim($data['name']), 'trigger' => $data['trigger'], 'delay_minutes' => $minutes, 'action' => $data['action'],
            'conditions' => $appointmentTrigger && ! empty($data['service_ids']) ? ['service_ids' => array_map('intval', $data['service_ids'])] : null,
            'action_config' => $config, 'is_active' => (bool) $data['is_active'],
        ];

        $automation = $this->editing === 'new'
            ? Automation::create($values + ['organization_id' => $organization->id, 'created_by_user_id' => auth()->id()])
            : tap($this->find((string) $this->editing))->update($values);
        $audit->changes($this->editing === 'new' ? 'automation.created' : 'automation.updated', $automation);

        $this->editing = null;
        $this->dispatch('toast', type: 'success', message: 'Automation saved.');
    }

    public function toggle(int $id, Audit $audit): void
    {
        $this->authorize('settings.manage', $this->organization());
        $a = Automation::query()->forOrganization($this->organization())->findOrFail($id);
        $a->update(['is_active' => ! $a->is_active]);
        $audit->changes('automation.updated', $a, ['is_active']);
    }

    public function delete(int $id, Audit $audit): void
    {
        $this->authorize('settings.manage', $this->organization());
        $a = Automation::query()->forOrganization($this->organization())->findOrFail($id);
        $audit->record('automation.deleted', $a, old: ['name' => $a->name, 'trigger' => $a->trigger, 'action' => $a->action]);
        $a->delete();
        $this->editing = null;
    }

    private function find(string $ulid): Automation
    {
        return Automation::query()->forOrganization($this->organization())->where('ulid', $ulid)->firstOrFail();
    }

    public function render(): View
    {
        $organization = $this->organization();

        return view('livewire.client.business.automations', [
            'automations' => Automation::query()->forOrganization($organization)
                ->withCount(['runs as pending_count' => fn ($q) => $q->where('status', 'pending'), 'runs as done_count' => fn ($q) => $q->where('status', 'done')])
                ->orderBy('name')->get(),
            'history' => AutomationRun::query()->forOrganization($organization)->with('automation:id,name')
                ->whereNotIn('status', ['pending', 'running'])->latest('ran_at')->limit(20)->get(),
            'upcoming' => AutomationRun::query()->forOrganization($organization)->where('status', 'pending')->count(),
            'services' => BusinessService::query()->forOrganization($organization)->orderBy('name')->get(['id', 'name']),
            'recipes' => self::RECIPES,
            'triggers' => Automation::TRIGGERS,
            'actions' => Automation::ACTIONS,
            'units' => array_keys(self::UNITS),
            'placeholders' => Automation::PLACEHOLDERS,
            'canManage' => auth()->user()->can('settings.manage', $organization),
        ]);
    }
}
