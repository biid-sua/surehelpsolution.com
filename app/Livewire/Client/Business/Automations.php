<?php

namespace App\Livewire\Client\Business;

use App\Enums\CustomerStatus;
use App\Enums\OutcomeCategory;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Appointment;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\BusinessService;
use App\Models\Customer;
use App\Models\Tag;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\Flow;
use App\Services\Telephony\Texting;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Business › Automations (spec §42–43, D50, D80, D81): "when this happens, wait, then do that", from
 * ready-made recipes or from scratch, with optional conditions and each run's history. An automation is a
 * flow of steps (actions, waits, and an "if / otherwise"), built visually and tried with a test run.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Automations')]
class Automations extends Component
{
    use ScopedToOrganization;

    public const UNITS = ['minutes' => 1, 'hours' => 60, 'days' => 1440];

    /** A test run tries an appointment in the state the trigger is about, when there is one. */
    private const STATUS_FOR = ['appointment_completed' => 'completed', 'appointment_cancelled' => 'cancelled', 'appointment_no_show' => 'no_show'];

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
        // Flows (D81): several steps, with waits and an if / otherwise.
        'review_chase' => [
            'name' => 'Review request with a text reminder',
            'trigger' => 'appointment_completed', 'delay' => 2, 'unit' => 'hours', 'action' => 'send_email',
            'config' => ['subject' => 'Thank you from {business}', 'consent_only' => true, 'review_url' => '',
                'body' => "Hi {first_name},\n\nThank you for choosing {business}. If you have a minute, a short review helps us a lot:\n{review_link}\n\nThank you,\n{business}"],
            'flow' => [
                ['type' => 'wait', 'minutes' => 4320],
                ['type' => 'branch', 'rules' => [['field' => 'replied', 'op' => 'no']], 'then' => [
                    ['type' => 'action', 'action' => 'send_text', 'config' => ['text' => 'Hi {first_name}, a quick review of {business} would mean a lot: {review_link}', 'consent_only' => true, 'review_url' => '']],
                ], 'else' => []],
            ],
        ],
        'lead_nurture' => [
            'name' => 'New lead: call, then follow up if they don\'t book',
            'trigger' => 'customer_created', 'delay' => 0, 'unit' => 'minutes', 'action' => 'create_task',
            'config' => ['title' => 'Call {first_name} while they\'re interested', 'due_hours' => 2],
            'flow' => [
                ['type' => 'wait', 'minutes' => 2880],
                ['type' => 'branch', 'rules' => [['field' => 'booked_since', 'op' => 'no']], 'then' => [
                    ['type' => 'action', 'action' => 'send_email', 'config' => ['subject' => 'Can we help, {first_name}?', 'consent_only' => true, 'review_url' => '',
                        'body' => "Hi {first_name},\n\nThanks for getting in touch with {business}. Whenever you're ready, you can book here: {booking_link}\nOr call us: {phone}\n\n{business}"]],
                    ['type' => 'action', 'action' => 'notify_team', 'config' => ['message' => 'No booking yet from {first_name}: worth another call.']],
                ], 'else' => [
                    ['type' => 'action', 'action' => 'set_status', 'config' => ['status' => 'customer']],
                ]],
            ],
        ],
    ];

    public ?string $editing = null;   // ulid, or "new"

    /** @var array<string, mixed> */
    public array $form = [];

    /** A test run's result (D81): what it was tried on, and each step. */
    public ?array $test = null;

    public string $testCustomer = '';

    public function mount(): void
    {
        $this->authorize('organization.view', $this->organization());
    }

    public function start(string $recipe = ''): void
    {
        $this->authorize('settings.manage', $this->organization());
        $r = self::RECIPES[$recipe] ?? ['name' => '', 'trigger' => 'appointment_completed', 'delay' => 0, 'unit' => 'hours', 'action' => 'send_email', 'config' => ['subject' => '', 'body' => '', 'consent_only' => true, 'review_url' => '']];
        $this->form = ['name' => $r['name'], 'trigger' => $r['trigger'], 'delay' => (string) $r['delay'], 'unit' => $r['unit'],
            'service_ids' => [], 'rules' => $r['rules'] ?? [], 'inactive_days' => (string) ($r['inactive_days'] ?? 180), 'is_active' => true,
            'steps' => $this->toForm([['type' => 'action', 'action' => $r['action'], 'config' => $r['config']], ...($r['flow'] ?? [])])];
        $this->resetValidation();
        $this->test = null;
        $this->editing = 'new';
    }

    public function edit(string $ulid): void
    {
        $this->authorize('settings.manage', $this->organization());
        $a = $this->find($ulid);
        [$delay, $unit] = $this->split($a->delay_minutes);
        $this->form = ['name' => $a->name, 'trigger' => $a->trigger, 'delay' => (string) $delay, 'unit' => $unit,
            'service_ids' => array_map('strval', (array) $a->condition('service_ids', [])), 'rules' => $a->rules(),
            'inactive_days' => (string) $a->condition('inactive_days', 180), 'is_active' => $a->is_active,
            'steps' => $this->toForm($a->flow())];
        $this->resetValidation();
        $this->test = null;
        $this->editing = $a->ulid;
    }

    /** @return array{0: int, 1: string} minutes as an amount and a unit */
    private function split(int $minutes): array
    {
        return $minutes % 1440 === 0 && $minutes > 0 ? [$minutes / 1440, 'days']
            : ($minutes % 60 === 0 && $minutes > 0 ? [$minutes / 60, 'hours'] : [$minutes, 'minutes']);
    }

    /**
     * Stored steps as form fields: an action's config is flattened next to its type.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function toForm(array $steps): array
    {
        return array_map(function (array $step) {
            return match ($step['type'] ?? '') {
                'wait' => ['type' => 'wait', 'amount' => (string) $this->split(max(1, (int) ($step['minutes'] ?? 60)))[0], 'unit' => $this->split(max(1, (int) ($step['minutes'] ?? 60)))[1]],
                'branch' => ['type' => 'branch', 'rules' => array_values((array) ($step['rules'] ?? [])),
                    'then' => $this->toForm(array_values((array) ($step['then'] ?? []))), 'else' => $this->toForm(array_values((array) ($step['else'] ?? [])))],
                default => ['type' => 'action', 'action' => (string) ($step['action'] ?? 'send_email')]
                    + array_map(fn ($v) => is_bool($v) ? $v : (string) $v, (array) ($step['config'] ?? [])) + $this->blankConfig(),
            };
        }, $steps);
    }

    /** @return array<string, mixed> */
    private function blankConfig(): array
    {
        return ['subject' => '', 'body' => '', 'consent_only' => true, 'review_url' => '', 'title' => '', 'due_hours' => '24', 'message' => '',
            'text' => '', 'to' => '', 'tag_id' => '', 'status' => ''];
    }

    /** @return array<string, mixed> */
    private function blankStep(string $type): array
    {
        return match ($type) {
            'wait' => ['type' => 'wait', 'amount' => '1', 'unit' => 'days'],
            'branch' => ['type' => 'branch', 'rules' => [['field' => 'replied', 'op' => 'no', 'value' => '']], 'then' => [], 'else' => []],
            default => ['type' => 'action', 'action' => 'notify_team'] + $this->blankConfig(),
        };
    }

    /** "" is the main list; "2.then" is the first path of the third step. */
    private function isList(string $list): bool
    {
        return $list === '' || (preg_match('/^(\d+)\.(then|else)$/', $list, $m) === 1 && $this->isStep($m[1], 'branch'));
    }

    /** A step's path, such as "3" or "2.else.0". */
    private function isStep(string $path, ?string $type = null): bool
    {
        $step = preg_match('/^\d+(\.(then|else)\.\d+)?$/', $path) === 1 ? data_get($this->form, 'steps.'.$path) : null;

        return is_array($step) && ($type === null || ($step['type'] ?? null) === $type);
    }

    private function listKey(string $list): string
    {
        return $list === '' ? 'steps' : 'steps.'.$list;
    }

    private function stepCount(): int
    {
        return iterator_count(Flow::walk((array) ($this->form['steps'] ?? [])));
    }

    public function addStep(string $list, string $type): void
    {
        $depth = $list === '' ? 1 : intdiv(count(explode('.', $list)), 2) + 1;
        if (! $this->isList($list) || ! in_array($type, ['wait', 'action', 'branch'], true) || ($type === 'branch' && $depth >= Automation::MAX_DEPTH)) {
            return;
        }
        if ($this->stepCount() >= Automation::MAX_STEPS) {
            $this->addError('form.steps', 'A flow can have at most '.Automation::MAX_STEPS.' steps.');

            return;
        }
        $items = (array) data_get($this->form, $this->listKey($list), []);
        $items[] = $this->blankStep($type);
        data_set($this->form, $this->listKey($list), $items);
        $this->test = null;
    }

    /** @return array{0: string, 1: int} the list a step is in, and its place */
    private function place(string $path): array
    {
        $parts = explode('.', $path);
        $index = (int) array_pop($parts);

        return [implode('.', $parts), $index];
    }

    public function removeStep(string $path): void
    {
        if (! $this->isStep($path)) {
            return;
        }
        [$list, $index] = $this->place($path);
        $items = (array) data_get($this->form, $this->listKey($list), []);
        array_splice($items, $index, 1);
        data_set($this->form, $this->listKey($list), array_values($items));
        $this->resetValidation();
        $this->test = null;
    }

    public function moveStep(string $path, int $by): void
    {
        if (! $this->isStep($path)) {
            return;
        }
        [$list, $index] = $this->place($path);
        $items = (array) data_get($this->form, $this->listKey($list), []);
        $to = $index + ($by < 0 ? -1 : 1);
        if (! isset($items[$index], $items[$to])) {
            return;
        }
        [$items[$index], $items[$to]] = [$items[$to], $items[$index]];
        data_set($this->form, $this->listKey($list), $items);
        $this->resetValidation();
        $this->test = null;
    }

    /** "" is the "Only if" list; otherwise the path of a branch step. */
    public function addRule(string $path = ''): void
    {
        if ($path !== '' && ! $this->isStep($path, 'branch')) {
            return;
        }
        $key = $path === '' ? 'rules' : $this->listKey($path).'.rules';
        $rules = (array) data_get($this->form, $key, []);
        if (count($rules) >= 5) {
            return;
        }
        $rules[] = $path === '' ? ['field' => 'customer_tag', 'op' => 'has', 'value' => ''] : ['field' => 'replied', 'op' => 'no', 'value' => ''];
        data_set($this->form, $key, $rules);
    }

    public function removeRule(int $index, string $path = ''): void
    {
        if ($path !== '' && ! $this->isStep($path, 'branch')) {
            return;
        }
        $key = $path === '' ? 'rules' : $this->listKey($path).'.rules';
        $rules = (array) data_get($this->form, $key, []);
        unset($rules[$index]);
        data_set($this->form, $key, array_values($rules));
    }

    /** Picking a different field resets the operator to that field's first one. */
    public function updatedForm(mixed $value, string $key): void
    {
        if (preg_match('/^(.*)rules\.(\d+)\.field$/', $key, $m)) {
            $ops = Automation::CONDITION_FIELDS[$value]['ops'] ?? [];
            data_set($this->form, $m[1].'rules.'.$m[2].'.op', (string) array_key_first($ops));
            data_set($this->form, $m[1].'rules.'.$m[2].'.value', '');
        }
        $this->test = null;
    }

    public function save(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);
        $values = $this->build();
        if ($values === null) {
            return;
        }

        $automation = $this->editing === 'new'
            ? Automation::create($values + ['organization_id' => $organization->id, 'created_by_user_id' => auth()->id()])
            : tap($this->find((string) $this->editing))->update($values);
        $audit->changes($this->editing === 'new' ? 'automation.created' : 'automation.updated', $automation);

        $this->editing = null;
        $this->test = null;
        $this->dispatch('toast', type: 'success', message: 'Automation saved.');
    }

    /**
     * Try the automation as it stands in the form on a real record, without sending anything (D81).
     */
    public function testRun(AutomationEngine $engine): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);
        $this->test = null;
        $values = $this->build();
        if ($values === null) {
            return;
        }
        $automation = (new Automation)->forceFill($values + ['organization_id' => $organization->id]);
        $subject = $this->sample($values['trigger'], (int) $this->testCustomer ?: null);
        if (! $subject) {
            $this->test = ['about' => null, 'lines' => [], 'empty' => 'There\'s nothing to try it on yet: no '.$this->subjectLabel($values['trigger']).($this->testCustomer ? ' for this customer' : '').'.'];

            return;
        }
        $this->test = ['about' => $this->describe($subject), 'lines' => $engine->simulate($automation, $subject), 'empty' => null];
    }

    /** The record a test run uses: the latest one the trigger could be about (the next one, for "before"). */
    private function sample(string $trigger, ?int $customerId): ?Model
    {
        $type = Automation::SUBJECT_FOR[$trigger] ?? 'customer';
        $class = AutomationRun::SUBJECTS[$type];
        /** @var Builder<Model> $query */
        $query = $class::query()->where('organization_id', $this->organization()->id);
        if ($customerId && $type !== 'review') {
            $type === 'customer' ? $query->whereKey($customerId) : $query->where('customer_id', $customerId);
        }

        return match (true) {
            $trigger === 'appointment_upcoming' => (clone $query)->where('starts_at', '>', now())->orderBy('starts_at')->first() ?? $query->latest('starts_at')->first(),
            $type === 'appointment' => (clone $query)->when(self::STATUS_FOR[$trigger] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->latest('starts_at')->first() ?? $query->latest('starts_at')->first(),
            default => $query->latest('id')->first(),
        };
    }

    private function subjectLabel(string $trigger): string
    {
        return ['appointment' => 'appointment', 'customer' => 'customer', 'call' => 'logged call', 'voice_call' => 'phone call',
            'conversation' => 'conversation', 'escalation' => 'escalation', 'task' => 'task', 'review' => 'review'][Automation::SUBJECT_FOR[$trigger] ?? 'customer'];
    }

    private function describe(Model $subject): string
    {
        $tz = $this->organization()->timezoneOrDefault();

        return match (true) {
            $subject instanceof Customer => $subject->fullName(),
            $subject instanceof Appointment => ($subject->customer?->fullName() ?: 'A customer').'\'s appointment on '.$subject->starts_at->setTimezone($tz)->format('M j, g:i A'),
            default => ($subject->getAttribute('customer_id') ? (Customer::withoutGlobalScopes()->find($subject->getAttribute('customer_id'))?->fullName() ?: 'A customer').': ' : '')
                .'the '.$this->subjectLabel((string) ($this->form['trigger'] ?? '')).' from '.$subject->getAttribute('created_at')?->setTimezone($tz)->format('M j, g:i A'),
        };
    }

    /**
     * Check the form and turn it into what's stored, or add errors and return null.
     *
     * @return array<string, mixed>|null
     */
    private function build(): ?array
    {
        $organization = $this->organization();
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:120'],
            'form.trigger' => ['required', Rule::in(array_keys(Automation::TRIGGERS))],
            'form.delay' => ['required', 'integer', 'min:0', 'max:43200'],
            'form.unit' => ['required', Rule::in(array_keys(self::UNITS))],
            'form.service_ids' => ['array'],
            'form.service_ids.*' => ['integer', Rule::exists('business_services', 'id')->where('organization_id', $organization->id)],
            'form.is_active' => ['boolean'],
            'form.inactive_days' => [Rule::requiredIf(($this->form['trigger'] ?? '') === 'customer_inactive'), 'nullable', 'integer', 'min:7', 'max:1095'],
            'form.rules' => ['array', 'max:5'],
            'form.steps' => ['required', 'array', 'min:1'],
        ], ['form.steps.required' => 'Add at least one step.', 'form.steps.min' => 'Add at least one step.'])['form'];

        $minutes = (int) $data['delay'] * self::UNITS[$data['unit']];
        if ($minutes > Automation::MAX_DELAY_MINUTES) {
            $this->addError('form.delay', 'Wait at most 30 days.');

            return null;
        }
        $rules = $this->normalizeRules((array) ($data['rules'] ?? []), 'form.rules', false);
        if ($this->stepCount() > Automation::MAX_STEPS) {
            $this->addError('form.steps', 'A flow can have at most '.Automation::MAX_STEPS.' steps.');

            return null;
        }
        $steps = $this->normalizeSteps(array_values((array) $data['steps']), 'form.steps', 1, $data['trigger']);
        if ($rules === null || $steps === null) {
            return null;
        }
        $first = null;
        foreach (Flow::walk($steps) as [$step]) {
            if ($step['type'] === 'action') {
                $first = $step;
                break;
            }
        }
        if ($first === null) {
            $this->addError('form.steps', 'Add at least one action: something for the flow to do.');

            return null;
        }
        $last = end($steps);
        if ($last['type'] === 'wait') {
            $this->addError('form.steps', 'The flow ends with a wait. Add a step after it, or remove it.');

            return null;
        }

        $appointmentTrigger = str_starts_with($data['trigger'], 'appointment_');
        // A single action is stored the way automations always were.
        $single = count($steps) === 1 && $steps[0]['type'] === 'action';

        return [
            'name' => trim($data['name']), 'trigger' => $data['trigger'], 'delay_minutes' => $minutes,
            'action' => $first['action'], 'action_config' => $first['config'], 'steps' => $single ? null : $steps,
            'conditions' => array_filter([
                'service_ids' => $appointmentTrigger && ! empty($data['service_ids']) ? array_map('intval', $data['service_ids']) : null,
                'inactive_days' => $data['trigger'] === 'customer_inactive' ? (int) $data['inactive_days'] : null,
                'rules' => $rules ?: null,
            ]) ?: null,
            'is_active' => (bool) $data['is_active'],
        ];
    }

    /**
     * @param  array<int, mixed>  $rules
     * @return list<array<string, string>>|null
     */
    private function normalizeRules(array $rules, string $prefix, bool $branch): ?array
    {
        $clean = [];
        foreach (array_values($rules) as $i => $rule) {
            $field = Automation::CONDITION_FIELDS[$rule['field'] ?? ''] ?? null;
            if (! $field || (! $branch && ($field['branch_only'] ?? false))) {
                $this->addError($prefix.'.'.$i.'.value', 'Choose a condition.');

                return null;
            }
            $value = (string) ($rule['value'] ?? '');
            if (! array_key_exists((string) ($rule['op'] ?? ''), $field['ops']) || ($field['value'] && ($value === '' || mb_strlen($value) > 40))) {
                $this->addError($prefix.'.'.$i.'.value', 'Finish this condition or remove it.');

                return null;
            }
            $clean[] = ['field' => (string) $rule['field'], 'op' => (string) $rule['op']] + ($field['value'] ? ['value' => $value] : []);
        }

        return $clean;
    }

    /**
     * @param  list<mixed>  $steps
     * @return list<array<string, mixed>>|null
     */
    private function normalizeSteps(array $steps, string $prefix, int $depth, string $trigger): ?array
    {
        $clean = [];
        foreach ($steps as $i => $step) {
            $at = $prefix.'.'.$i;
            $type = is_array($step) ? ($step['type'] ?? '') : '';
            if ($type === 'wait') {
                $amount = filter_var($step['amount'] ?? null, FILTER_VALIDATE_INT);
                $unit = (string) ($step['unit'] ?? '');
                $minutes = $amount !== false && isset(self::UNITS[$unit]) ? $amount * self::UNITS[$unit] : 0;
                if ($minutes < 1 || $minutes > Automation::MAX_DELAY_MINUTES) {
                    $this->addError($at.'.amount', 'Wait between 1 minute and 30 days.');

                    return null;
                }
                $clean[] = ['type' => 'wait', 'minutes' => $minutes];
            } elseif ($type === 'branch' && $depth < Automation::MAX_DEPTH) {
                $rules = (array) ($step['rules'] ?? []);
                if ($rules === [] || count($rules) > 5) {
                    $this->addError($at.'.rules', 'Add a condition to decide which way to go (up to 5).');

                    return null;
                }
                $rules = $this->normalizeRules($rules, $at.'.rules', true);
                $then = $this->normalizeSteps(array_values((array) ($step['then'] ?? [])), $at.'.then', $depth + 1, $trigger);
                $else = $rules !== null && $then !== null ? $this->normalizeSteps(array_values((array) ($step['else'] ?? [])), $at.'.else', $depth + 1, $trigger) : null;
                if ($rules === null || $then === null || $else === null) {
                    return null;
                }
                if ($then === [] && $else === []) {
                    $this->addError($at.'.rules', 'Add a step to at least one of the paths.');

                    return null;
                }
                $clean[] = ['type' => 'branch', 'rules' => $rules, 'then' => $then, 'else' => $else];
            } elseif ($type === 'action') {
                $config = $this->actionConfig($step, $at, $trigger);
                if ($config === null) {
                    return null;
                }
                $clean[] = ['type' => 'action', 'action' => (string) $step['action'], 'config' => $config];
            } else {
                $this->addError($at.'.type', 'This step isn\'t allowed here.');

                return null;
            }
        }

        return $clean;
    }

    /**
     * One action step's settings, checked; null (with errors) when they're not right.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>|null
     */
    private function actionConfig(array $step, string $at, string $trigger): ?array
    {
        $organization = $this->organization();
        $action = (string) ($step['action'] ?? '');
        $validator = Validator::make($step, [
            'action' => ['required', Rule::in(array_keys(Automation::ACTIONS))],
            'subject' => [Rule::requiredIf(in_array($action, ['send_email', 'email_address'], true)), 'nullable', 'string', 'max:200'],
            'body' => [Rule::requiredIf(in_array($action, ['send_email', 'email_address'], true)), 'nullable', 'string', 'max:5000'],
            'consent_only' => ['boolean'],
            'review_url' => [Rule::requiredIf(($action === 'send_email' && str_contains((string) ($step['body'] ?? ''), '{review_link}'))
                || ($action === 'send_text' && str_contains((string) ($step['text'] ?? ''), '{review_link}'))), 'nullable', 'url:https', 'max:500'],
            'title' => [Rule::requiredIf($action === 'create_task'), 'nullable', 'string', 'max:200'],
            'due_hours' => [Rule::requiredIf($action === 'create_task'), 'nullable', 'integer', 'min:0', 'max:720'],
            'message' => [Rule::requiredIf($action === 'notify_team'), 'nullable', 'string', 'max:500'],
            'text' => [Rule::requiredIf($action === 'send_text'), 'nullable', 'string', 'max:480'],
            'to' => [Rule::requiredIf($action === 'email_address'), 'nullable', 'email', 'max:190'],
            'tag_id' => [Rule::requiredIf(in_array($action, ['add_tag', 'remove_tag'], true)), 'nullable', 'integer',
                Rule::exists('tags', 'id')->where('organization_id', $organization->id)],
            'status' => [Rule::requiredIf($action === 'set_status'), 'nullable', Rule::in(array_map(fn (CustomerStatus $c) => $c->value, CustomerStatus::cases()))],
        ], [], ['review_url' => 'review link', 'body' => 'message', 'due_hours' => 'due time', 'tag_id' => 'tag', 'to' => 'address']);
        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $key => $messages) {
                $this->addError($at.'.'.$key, $messages[0]);
            }

            return null;
        }
        if ($trigger === 'task_overdue' && $action === 'create_task') {
            $this->addError($at.'.action', 'An overdue task can\'t create another task. Notify your team instead.');

            return null;
        }
        if (in_array($action, ['send_email', 'send_text'], true) && in_array($trigger, ['task_overdue', 'escalation_raised', 'review_received'], true)) {
            $this->addError($at.'.action', 'Tell your team about this one; customers shouldn\'t hear about internal tasks.');

            return null;
        }

        return match ($action) {
            'send_email' => ['subject' => trim((string) $step['subject']), 'body' => trim((string) $step['body']), 'consent_only' => (bool) ($step['consent_only'] ?? true), 'review_url' => trim((string) ($step['review_url'] ?? ''))],
            'create_task' => ['title' => trim((string) $step['title']), 'due_hours' => (int) $step['due_hours']],
            'send_text' => ['text' => trim((string) $step['text']), 'consent_only' => (bool) ($step['consent_only'] ?? true), 'review_url' => trim((string) ($step['review_url'] ?? ''))],
            'email_address' => ['to' => trim((string) $step['to']), 'subject' => trim((string) $step['subject']), 'body' => trim((string) $step['body'])],
            'add_tag', 'remove_tag' => ['tag_id' => (int) $step['tag_id']],
            'set_status' => ['status' => (string) $step['status']],
            default => ['message' => trim((string) $step['message'])],
        };
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
            'maxSteps' => Automation::MAX_STEPS,
            'maxDepth' => Automation::MAX_DEPTH,
            'stepCount' => $this->editing ? $this->stepCount() : 0,
            'testCustomers' => $this->editing ? Customer::query()->forOrganization($organization)->whereNot('status', CustomerStatus::Archived)
                ->latest('id')->limit(50)->get() : collect(),
            'units' => array_keys(self::UNITS),
            'placeholders' => Automation::PLACEHOLDERS,
            'canManage' => auth()->user()->can('settings.manage', $organization),
        ]);
    }
}
