<?php

namespace App\Livewire\Client;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Lead;
use App\Models\LeadSettings;
use App\Services\Telephony\Texting;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * New leads and the instant reply (D109): a text and an email within seconds, and a call that
 * connects the owner. Also the business's lead link for Facebook, Google, Angi, Thumbtack, Yelp…
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Leads')]
class Leads extends Component
{
    use ScopedToOrganization, WithPagination;

    /** @var array{is_enabled: bool, text_lead: bool, text_message: string, email_lead: bool, email_message: string, connect_call: bool, call_in_hours_only: bool} */
    public array $form = ['is_enabled' => false, 'text_lead' => true, 'text_message' => '', 'email_lead' => true, 'email_message' => '', 'connect_call' => false, 'call_in_hours_only' => true];

    public function mount(): void
    {
        $this->authorize('customers.view', $this->organization());
        $settings = LeadSettings::for($this->organization());
        $this->form = [
            'is_enabled' => $settings->is_enabled, 'text_lead' => $settings->text_lead, 'text_message' => (string) $settings->text_message,
            'email_lead' => $settings->email_lead, 'email_message' => (string) $settings->email_message,
            'connect_call' => $settings->connect_call, 'call_in_hours_only' => $settings->call_in_hours_only,
        ];
    }

    public function save(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        $data = $this->validate([
            'form.is_enabled' => ['boolean'], 'form.text_lead' => ['boolean'], 'form.email_lead' => ['boolean'],
            'form.connect_call' => ['boolean'], 'form.call_in_hours_only' => ['boolean'],
            'form.text_message' => ['nullable', 'string', 'max:320'],
            'form.email_message' => ['nullable', 'string', 'max:2000'],
        ])['form'];

        $settings = LeadSettings::for($organization);
        $settings->fill([...$data, 'text_message' => trim((string) $data['text_message']) ?: null, 'email_message' => trim((string) $data['email_message']) ?: null]);
        $dirty = array_keys($settings->getDirty());
        $settings->save();
        if ($dirty !== []) {
            $audit->changes('leads.settings_updated', $settings, $dirty, $organization);
        }
        $this->dispatch('toast', type: 'success', message: $data['is_enabled'] ? 'Saved. New leads get an answer straight away.' : 'Saved. Instant replies are off.');
    }

    /** A new secret link; the old one stops working at once (if it was shared by mistake). */
    public function newLink(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        $settings = LeadSettings::for($organization);
        $settings->forceFill(['webhook_token' => null])->token();
        $audit->record('leads.link_replaced', $settings, organization: $organization, label: 'Lead link');
        $this->dispatch('toast', type: 'success', message: 'New lead link made. Update it in Zapier or wherever you used the old one.');
    }

    public function render(Texting $texting): View
    {
        $organization = $this->organization();
        $canManage = auth()->user()->can('organization.update', $organization);
        $settings = LeadSettings::for($organization);
        $month = Lead::query()->forOrganization($organization)->where('received_at', '>=', CarbonImmutable::now($organization->timezoneOrDefault())->startOfMonth()->utc());
        /** @var list<int> $times seconds to the first answer, fastest first */
        $times = (clone $month)->whereNotNull('first_response_at')->get(['received_at', 'first_response_at'])
            ->map(fn (Lead $l) => (int) $l->responseSeconds())->sort()->values()->all();
        $count = (clone $month)->count();
        $line = $texting->lineFor($organization);

        return view('livewire.client.leads', [
            'leads' => Lead::query()->forOrganization($organization)->latest('received_at')->paginate(15),
            'stats' => [
                'count' => $count,
                'median' => $times === [] ? null : $times[intdiv(count($times), 2)],
                'within_minute' => $times === [] ? null : (int) round(count(array_filter($times, fn (int $s) => $s <= 60)) / max(1, $count) * 100),
            ],
            'link' => $canManage ? route('leads.webhook', $settings->token()) : null,
            'canManage' => $canManage,
            'texting' => $texting->enabled() && $line !== null,
            'ownerNumber' => $line?->transfer_number,
            'sources' => Lead::SOURCES,
            'timezone' => $organization->timezoneOrDefault(),
        ]);
    }
}
