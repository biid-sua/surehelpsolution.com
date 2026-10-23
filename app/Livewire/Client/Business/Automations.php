<?php

namespace App\Livewire\Client\Business;

use App\Enums\CustomerStatus;
use App\Enums\OutcomeCategory;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\BusinessService;
use App\Models\Tag;
use App\Services\Telephony\Texting;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Business › Automations (spec §42–43, D50, D80): "when this happens, wait, then do that", from ready-made
 * recipes or from scratch, with optional conditions and each run's history.
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
        'review_text' => [
            'name' => 'Review request by text',
            'trigger' => 'appointment_completed', 'delay' => 3, 'unit' => 'hours', 'action' => 'send_text',
            'config' => ['text' => 'Hi {first_name}, thanks for choosing {business}! If you have a moment, a quick review means a lot: {review_link}', 'consent_only' => true, 'review_url' => ''],
        ],
        'rebook' => [
            'name' => 'Rebook cancelled appointments',
            'trigger' => 'appointment_cancelled', 'delay' => 1, 'unit' => 'days', 'action' => 'create_task',
            'config' => ['title' => 'Call {first_name} to rebook {service}', 'due_hours' => 24],
        ],
        'no_show' => [
            'name' => 'Missed appointment: offer a new time',
            'trigger' => 'appointment_no_show', 'delay' => 30, 'unit' => 'minutes', 'action' => 'send_text',
            'config' => ['text' => 'Hi {first_name}, we missed you today at {business}. Reply here or call {phone} and we\'ll find a new time.', 'consent_only' => true],
        ],
        'prepare' => [
            'name' => 'How to prepare, 2 days before',
            'trigger' => 'appointment_upcoming', 'delay' => 2, 'unit' => 'days', 'action' => 'send_email',
            'config' => ['subject' => 'Your {service} on {date}', 'consent_only' => false, 'review_url' => '',
                'body' => "Hi {first_name},\n\nYour {service} with {business} is on {date} at {time}.\n\nTo get ready: [add what the customer should do before you arrive].\n\nSee you then,\n{business}"],
        ],
        'welcome' => [
            'name' => 'Welcome first-time customers',
            'trigger' => 'appointment_booked', 'delay' => 0, 'unit' => 'minutes', 'action' => 'send_email',
            'rules' => [['field' => 'first_visit', 'op' => 'yes', 'value' => '']],
            'config' => ['subject' => 'Welcome to {business}', 'consent_only' => false, 'review_url' => '',
                'body' => "Hi {first_name},\n\nThanks for booking your first {service} with us on {date} at {time}. If anything changes, just reply to this email or call {phone}.\n\n{business}"],
        ],
        'missed_call' => [
            'name' => 'Missed call after hours: call back first thing',
            'trigger' => 'call_missed', 'delay' => 0, 'unit' => 'minutes', 'action' => 'create_task',
            'rules' => [['field' => 'business_hours', 'op' => 'closed', 'value' => '']],
            'config' => ['title' => 'Call back {first_name}: missed after hours', 'due_hours' => 12],
        ],
        'lead' => [
            'name' => 'Tell the team about new leads',
            'trigger' => 'customer_created', 'delay' => 0, 'unit' => 'minutes', 'action' => 'notify_team',
            'config' => ['message' => 'New lead: {first_name}. Give them a call while they\'re interested.'],
        ],
        'win_back' => [
            'name' => 'Win back customers after 6 months',
            'trigger' => 'customer_inactive', 'delay' => 0, 'unit' => 'minutes', 'action' => 'send_email', 'inactive_days' => 180,
            'config' => ['subject' => 'It\'s been a while, {first_name}', 'consent_only' => true, 'review_url' => '',
                'body' => "Hi {first_name},\n\nIt's been a while since your last visit to {business}. Is it time for your next {service}?\n\nBook online: {booking_link}\nOr call us: {phone}\n\n{business}"],
        ],
        'escalation' => [
            'name' => 'Email the owner about escalations',
            'trigger' => 'escalation_raised', 'delay' => 0, 'unit' => 'minutes', 'action' => 'email_address',
            'config' => ['to' => '', 'subject' => 'Escalation: {reason}', 'body' => "{customer} needs you: {reason}.\n\nOpen SureHelp to see the call and respond."],
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
            'service_ids' => [], 'rules' => $r['rules'] ?? [], 'inactive_days' => (string) ($r['inactive_days'] ?? 180), 'is_active' => true]
            + $r['config'] + $this->blankConfig();
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
            'service_ids' => array_map('strval', (array) $a->condition('service_ids', [])), 'rules' => $a->rules(),
            'inactive_days' => (string) $a->condition('inactive_days', 180), 'is_active' => $a->is_active]
            + array_map(fn ($v) => is_bool($v) ? $v : (string) $v, $a->action_config ?? []) + $this->blankConfig();
        $this->resetValidation();
        $this->editing = $a->ulid;
    }

    /** @return array<string, mixed> */
    private function blankConfig(): array
    {
        return ['subject' => '', 'body' => '', 'consent_only' => true, 'review_url' => '', 'title' => '', 'due_hours' => '24', 'message' => '',
            'text' => '', 'to' => '', 'tag_id' => '', 'status' => ''];
    }

    public function addRule(): void
    {
        $this->form['rules'][] = ['field' => 'customer_tag', 'op' => 'has', 'value' => ''];
    }

    public function removeRule(int $index): void
    {
        unset($this->form['rules'][$index]);
        $this->form['rules'] = array_values($this->form['rules']);
    }

    /** Picking a different field resets the operator to that field's first one. */
    public function updatedForm(mixed $value, string $key): void
    {
        if (preg_match('/^rules\.(\d+)\.field$/', $key, $m)) {
            $ops = Automation::CONDITION_FIELDS[$value]['ops'] ?? [];
            $this->form['rules'][(int) $m[1]]['op'] = (string) array_key_first($ops);
            $this->form['rules'][(int) $m[1]]['value'] = '';
        }
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
            'form.subject' => [Rule::requiredIf(in_array($action, ['send_email', 'email_address'], true)), 'nullable', 'string', 'max:200'],
            'form.body' => [Rule::requiredIf(in_array($action, ['send_email', 'email_address'], true)), 'nullable', 'string', 'max:5000'],
            'form.consent_only' => ['boolean'],
            'form.review_url' => [Rule::requiredIf(($action === 'send_email' && str_contains((string) ($this->form['body'] ?? ''), '{review_link}'))
                || ($action === 'send_text' && str_contains((string) ($this->form['text'] ?? ''), '{review_link}'))), 'nullable', 'url:https', 'max:500'],
            'form.title' => [Rule::requiredIf($action === 'create_task'), 'nullable', 'string', 'max:200'],
            'form.due_hours' => [Rule::requiredIf($action === 'create_task'), 'nullable', 'integer', 'min:0', 'max:720'],
            'form.message' => [Rule::requiredIf($action === 'notify_team'), 'nullable', 'string', 'max:500'],
            'form.text' => [Rule::requiredIf($action === 'send_text'), 'nullable', 'string', 'max:480'],
            'form.to' => [Rule::requiredIf($action === 'email_address'), 'nullable', 'email', 'max:190'],
            'form.tag_id' => [Rule::requiredIf(in_array($action, ['add_tag', 'remove_tag'], true)), 'nullable', 'integer',
                Rule::exists('tags', 'id')->where('organization_id', $organization->id)],
            'form.status' => [Rule::requiredIf($action === 'set_status'), 'nullable', Rule::in(array_map(fn (CustomerStatus $c) => $c->value, CustomerStatus::cases()))],
            'form.inactive_days' => [Rule::requiredIf(($this->form['trigger'] ?? '') === 'customer_inactive'), 'nullable', 'integer', 'min:7', 'max:1095'],
            'form.rules' => ['array', 'max:5'],
            'form.rules.*.field' => ['required', Rule::in(array_keys(Automation::CONDITION_FIELDS))],
            'form.rules.*.op' => ['required', 'string', 'max:10'],
            'form.rules.*.value' => ['nullable', 'string', 'max:40'],
        ], attributes: ['form.review_url' => 'review link', 'form.body' => 'message', 'form.due_hours' => 'due time'])['form'];

        if ($data['trigger'] === 'task_overdue' && $data['action'] === 'create_task') {
            $this->addError('form.action', 'An overdue task can\'t create another task. Notify your team instead.');

            return;
        }
        if (in_array($data['action'], ['send_email', 'send_text'], true) && in_array($data['trigger'], ['task_overdue', 'escalation_raised'], true)) {
            $this->addError('form.action', 'Tell your team about this one; customers shouldn\'t hear about internal tasks.');

            return;
        }
        $rules = [];
        foreach ($data['rules'] ?? [] as $i => $rule) {
            $field = Automation::CONDITION_FIELDS[$rule['field']];
            if (! array_key_exists($rule['op'], $field['ops']) || ($field['value'] && blank($rule['value'] ?? null))) {
                $this->addError('form.rules.'.$i.'.value', 'Finish this condition or remove it.');

                return;
            }
            $rules[] = ['field' => $rule['field'], 'op' => $rule['op']] + ($field['value'] ? ['value' => (string) $rule['value']] : []);
        }

        $minutes = (int) $data['delay'] * self::UNITS[$data['unit']];
        if ($minutes > Automation::MAX_DELAY_MINUTES) {
            $this->addError('form.delay', 'Wait at most 30 days.');

            return;
        }

        $config = match ($data['action']) {
            'send_email' => ['subject' => trim((string) $data['subject']), 'body' => trim((string) $data['body']), 'consent_only' => (bool) ($data['consent_only'] ?? true), 'review_url' => trim((string) ($data['review_url'] ?? ''))],
            'create_task' => ['title' => trim((string) $data['title']), 'due_hours' => (int) $data['due_hours']],
            'send_text' => ['text' => trim((string) $data['text']), 'consent_only' => (bool) ($data['consent_only'] ?? true), 'review_url' => trim((string) ($data['review_url'] ?? ''))],
            'email_address' => ['to' => trim((string) $data['to']), 'subject' => trim((string) $data['subject']), 'body' => trim((string) $data['body'])],
            'add_tag', 'remove_tag' => ['tag_id' => (int) $data['tag_id']],
            'set_status' => ['status' => (string) $data['status']],
            default => ['message' => trim((string) $data['message'])],
        };
        $appointmentTrigger = str_starts_with($data['trigger'], 'appointment_');
        $values = [
            'name' => trim($data['name']), 'trigger' => $data['trigger'], 'delay_minutes' => $minutes, 'action' => $data['action'],
            'conditions' => array_filter([
                'service_ids' => $appointmentTrigger && ! empty($data['service_ids']) ? array_map('intval', $data['service_ids']) : null,
                'inactive_days' => $data['trigger'] === 'customer_inactive' ? (int) $data['inactive_days'] : null,
                'rules' => $rules ?: null,
            ]) ?: null,
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
            'triggerGroups' => Automation::TRIGGER_GROUPS,
            'conditionFields' => Automation::CONDITION_FIELDS,
            'tags' => Tag::query()->forOrganization($organization)->orderBy('name')->get(['id', 'name']),
            'statuses' => collect(CustomerStatus::cases())->reject(fn ($c) => $c === CustomerStatus::Archived)->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all(),
            'outcomes' => collect(OutcomeCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all(),
            'texting' => app(Texting::class)->enabled() && app(Texting::class)->lineFor($organization) !== null,
            'actions' => Automation::ACTIONS,
            'units' => array_keys(self::UNITS),
            'placeholders' => Automation::PLACEHOLDERS,
            'canManage' => auth()->user()->can('settings.manage', $organization),
        ]);
    }
}
