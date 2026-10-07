<?php

namespace App\Livewire\Client\Business;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\BusinessProfile;
use App\Models\BusinessService;
use App\Models\MessageTemplate;
use App\Notifications\CustomerEmail;
use App\Services\Appointments\CustomerSelfService;
use App\Services\Messages\CustomerMessages;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Business › Customer emails: the business's own wording for appointment emails to its customers.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Customer emails')]
class CustomerEmails extends Component
{
    use ScopedToOrganization;

    public ?string $editing = null;

    /** @var array{subject: string, body: string, is_active: bool, lead_hours: int|string|null} */
    public array $draft = ['subject' => '', 'body' => '', 'is_active' => true, 'lead_hours' => null];

    /** Hours before an appointment that customers can still change it online, or "off" (CAL-09). */
    public string $changeHours = 'off';

    public function mount(): void
    {
        $this->authorize('organization.view', $this->organization());
        $hours = $this->organization()->customer_change_hours;
        $this->changeHours = $hours === null ? 'off' : (string) $hours;
    }

    public function saveSelfService(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);
        $this->validate(['changeHours' => ['required', Rule::in(['off', ...array_map('strval', CustomerSelfService::HOUR_OPTIONS)])]]);

        $organization->forceFill(['customer_change_hours' => $this->changeHours === 'off' ? null : (int) $this->changeHours])->save();
        $audit->changes('organization.updated', $organization, ['customer_change_hours']);
        $this->dispatch('toast', type: 'success', message: $this->changeHours === 'off' ? 'Customers will call you to change bookings.' : 'Saved. Emails now include a link to change or cancel.');
    }

    public function edit(string $key, CustomerMessages $messages): void
    {
        $this->authorize('settings.manage', $this->organization());
        abort_unless(array_key_exists($key, config('customer_messages.templates')), 404);
        $t = $messages->template($this->organization(), $key);
        $this->editing = $key;
        $this->draft = ['subject' => $t['subject'], 'body' => $t['body'], 'is_active' => $t['is_active'], 'lead_hours' => $t['lead_hours']];
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function save(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);
        $key = (string) $this->editing;
        abort_unless(array_key_exists($key, config('customer_messages.templates')), 404);
        $this->validate([
            'draft.subject' => ['required', 'string', 'max:200'],
            'draft.body' => ['required', 'string', 'max:5000'],
            'draft.is_active' => ['boolean'],
            'draft.lead_hours' => $key === 'appointment_reminder' ? ['required', Rule::in([2, 4, 12, 24, 48])] : ['nullable'],
        ], [], ['draft.subject' => 'subject', 'draft.body' => 'message', 'draft.lead_hours' => 'reminder time']);

        $unknown = array_diff($this->placeholders($this->draft['subject'].' '.$this->draft['body']), array_keys(config('customer_messages.placeholders')));
        if ($unknown) {
            $this->addError('draft.body', 'Unknown placeholder: {'.implode('}, {', $unknown).'}. Use the ones listed below.');

            return;
        }

        $template = MessageTemplate::query()->forOrganization($organization)->firstOrNew(['organization_id' => $organization->id, 'key' => $key]);
        $template->fill([
            'subject' => trim($this->draft['subject']),
            'body' => trim($this->draft['body']),
            'is_active' => (bool) $this->draft['is_active'],
            'lead_hours' => $key === 'appointment_reminder' ? (int) $this->draft['lead_hours'] : null,
        ])->save();
        $audit->changes('message_template.updated', $template, ['subject', 'body', 'is_active', 'lead_hours']);

        $this->editing = null;
        $this->dispatch('toast', type: 'success', message: 'Saved. The next emails use the new wording.');
    }

    public function toggle(string $key, CustomerMessages $messages, Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);
        $t = $messages->template($organization, $key);
        $template = MessageTemplate::query()->forOrganization($organization)->firstOrNew(['organization_id' => $organization->id, 'key' => $key]);
        $template->fill(['subject' => $t['subject'], 'body' => $t['body'], 'lead_hours' => $t['lead_hours'], 'is_active' => ! $t['is_active']])->save();
        $audit->changes('message_template.updated', $template, ['is_active']);
    }

    public function restoreDefault(Audit $audit): void
    {
        $this->authorize('settings.manage', $this->organization());
        $default = config('customer_messages.templates.'.$this->editing);
        $this->draft['subject'] = $default['subject'];
        $this->draft['body'] = $default['body'];
    }

    public function sendTest(CustomerMessages $messages): void
    {
        $this->authorize('settings.manage', $this->organization());
        $values = $this->sampleValues($messages);
        Notification::route('mail', auth()->user()->email)->notify(new CustomerEmail(
            '[Test] '.$messages->render($this->draft['subject'], $values), $messages->render($this->draft['body'], $values), $values['business'],
        ));
        $this->dispatch('toast', type: 'success', message: 'Test sent to '.auth()->user()->email.'.');
    }

    public function render(CustomerMessages $messages): View
    {
        $organization = $this->organization();
        $values = $this->sampleValues($messages);

        return view('livewire.client.business.customer-emails', [
            'templates' => collect(config('customer_messages.templates'))->map(fn (array $d, string $key) => $d + ['current' => $messages->template($organization, $key)])->all(),
            'placeholders' => config('customer_messages.placeholders'),
            'preview' => $this->editing ? ['subject' => $messages->render($this->draft['subject'], $values), 'body' => $messages->render($this->draft['body'], $values)] : null,
            'canEdit' => auth()->user()->can('settings.manage', $organization),
            'hourOptions' => CustomerSelfService::HOUR_OPTIONS,
        ]);
    }

    /** @return array<string, string> */
    private function sampleValues(CustomerMessages $messages): array
    {
        $organization = $this->organization();
        $start = CarbonImmutable::now($organization->timezoneOrDefault())->addDays(2)->setTime(10, 0);
        $profile = BusinessProfile::query()->forOrganization($organization)->first();

        return [
            'first_name' => 'Ana',
            'business' => $profile?->display_name ?: $organization->name,
            'service' => BusinessService::query()->forOrganization($organization)->value('name') ?? 'Service visit',
            'date' => $start->format('l, F j'),
            'time' => $start->format('g:i A'),
            'address' => '123 Main St, Austin TX 78701',
            'phone' => (string) ($profile?->phone ?: '(512) 555-0100'),
        ];
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/\{(\w+)\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }
}
