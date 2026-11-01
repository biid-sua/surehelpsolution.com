<?php

namespace App\Livewire\Client;

use App\Enums\AppointmentStatus;
use App\Jobs\SendCampaign;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Appointment;
use App\Models\BusinessService;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ServiceReminder;
use App\Models\Tag;
use App\Services\Marketing\Outreach as OutreachService;
use App\Support\Audit\Audit;
use App\Support\DemoGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Reminders & win-back (D116): reminders some months after each kind of job, and one-off campaigns
 * to past customers, with how many came back. Owners change them; the team can look.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Reminders & win-back')]
class Outreach extends Component
{
    use ScopedToOrganization;

    /** @var array<int, array{months: string, text: string}> by service id */
    public array $reminders = [];

    /** @var array<string, mixed> the campaign being written; the browser can send any shape back */
    public array $campaign = [];

    public function mount(): void
    {
        $this->authorize('customers.view', $this->organization());
        foreach ($this->services() as $service) {
            $this->reminders[$service->id] = ['months' => (string) ($service->reminder_months ?: ''), 'text' => (string) $service->reminder_text];
        }
        $this->resetCampaign();
    }

    private function resetCampaign(): void
    {
        $this->campaign = [
            'name' => '', 'inactive_months' => '12', 'tag_id' => '', 'service_id' => '', 'channels' => ['sms', 'email'],
            'text_body' => 'Hi {first_name}, it\'s {business}. It\'s been a while! Book this month and we\'ll check your system for free. Reply or call {phone}.',
            'email_subject' => 'We miss you at {business}', 'email_body' => "Hi {first_name},\n\nIt's been a while since we last helped you. Book this month and we'll check your system for free.\n\nCall us at {phone}, or just reply to this email.",
        ];
    }

    public function saveReminders(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        $this->validate([
            'reminders.*.months' => ['nullable', 'integer', 'min:1', 'max:36'],
            'reminders.*.text' => ['nullable', 'string', 'max:300'],
        ], [], ['reminders.*.months' => 'months', 'reminders.*.text' => 'message']);
        $changed = [];
        foreach ($this->services() as $service) {
            $months = (int) ($this->reminders[$service->id]['months'] ?? 0) ?: null;
            $text = trim((string) ($this->reminders[$service->id]['text'] ?? '')) ?: null;
            if ($service->reminder_months !== $months || $service->reminder_text !== $text) {
                $service->forceFill(['reminder_months' => $months, 'reminder_text' => $text])->save();
                $changed[$service->name] = $months;
            }
        }
        if ($changed !== []) {
            $audit->record('marketing.reminders_updated', null, new: $changed, organization: $organization, label: 'Service reminders');
        }
        $this->dispatch('toast', type: 'success', message: 'Service reminders saved.');
    }

    public function launch(Audit $audit, OutreachService $outreach): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        $data = $this->validate([
            'campaign.name' => ['required', 'string', 'max:120'],
            'campaign.inactive_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'campaign.tag_id' => ['nullable', Rule::exists('tags', 'id')->where('organization_id', $organization->id)],
            'campaign.service_id' => ['nullable', Rule::exists('business_services', 'id')->where('organization_id', $organization->id)],
            'campaign.channels' => ['required', 'array', 'min:1'],
            'campaign.channels.*' => ['in:sms,email'],
            'campaign.text_body' => [Rule::requiredIf(in_array('sms', $this->campaign['channels'] ?? [], true)), 'nullable', 'string', 'max:300'],
            'campaign.email_subject' => [Rule::requiredIf(in_array('email', $this->campaign['channels'] ?? [], true)), 'nullable', 'string', 'max:150'],
            'campaign.email_body' => [Rule::requiredIf(in_array('email', $this->campaign['channels'] ?? [], true)), 'nullable', 'string', 'max:5000'],
        ], ['campaign.channels.required' => 'Choose text, email or both.'], ['campaign.name' => 'name'])['campaign'];
        if (DemoGuard::isDemo($organization->id)) {
            throw ValidationException::withMessages(['campaign.name' => 'Demo businesses don\'t send campaigns.']);
        }
        $audience = array_filter(['inactive_months' => (int) ($data['inactive_months'] ?? 0) ?: null, 'tag_id' => (int) ($data['tag_id'] ?? 0) ?: null,
            'service_id' => (int) ($data['service_id'] ?? 0) ?: null]);
        $customers = $outreach->audience($organization, $audience)->limit(5000)->get()
            ->filter(fn ($c) => array_intersect($data['channels'], $outreach->reachableBy($c, $organization)) !== []);
        if ($customers->isEmpty()) {
            throw ValidationException::withMessages(['campaign.name' => 'Nobody in this group can be reached the ways you chose.']);
        }
        $campaign = Campaign::create([
            'organization_id' => $organization->id, 'created_by_user_id' => auth()->id(), 'name' => trim($data['name']), 'status' => Campaign::SENDING,
            'audience' => $audience, 'channels' => array_values($data['channels']),
            'text_body' => in_array('sms', $data['channels'], true) ? trim((string) $data['text_body']) : null,
            'email_subject' => in_array('email', $data['channels'], true) ? trim((string) $data['email_subject']) : null,
            'email_body' => in_array('email', $data['channels'], true) ? trim((string) $data['email_body']) : null,
            'recipients_count' => $customers->count(),
        ]);
        foreach ($customers->chunk(500) as $chunk) {
            CampaignRecipient::query()->insert($chunk->map(fn ($c) => ['campaign_id' => $campaign->id, 'organization_id' => $organization->id,
                'customer_id' => $c->id, 'created_at' => now(), 'updated_at' => now()])->values()->all());
        }
        $audit->record('marketing.campaign_started', $campaign, new: ['recipients' => $campaign->recipients_count, 'channels' => $campaign->channels], label: $campaign->name);
        SendCampaign::dispatch($campaign->id);
        $this->resetCampaign();
        $this->dispatch('toast', type: 'success', message: 'Campaign started for '.number_format($campaign->recipients_count).' customers. Texts and emails go out between 9 AM and 8 PM.');
    }

    /** @return Collection<int, BusinessService> */
    private function services(): Collection
    {
        return BusinessService::query()->forOrganization($this->organization())->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
    }

    public function render(OutreachService $outreach): View
    {
        $organization = $this->organization();
        $audience = array_filter(['inactive_months' => (int) ($this->campaign['inactive_months'] ?? 0) ?: null, 'tag_id' => (int) ($this->campaign['tag_id'] ?? 0) ?: null,
            'service_id' => (int) ($this->campaign['service_id'] ?? 0) ?: null]);
        $group = $outreach->audience($organization, $audience);
        $since = now()->subDays(90);
        $reminded = ServiceReminder::query()->forOrganization($organization)->where('sent_at', '>=', $since)->where('channels', '!=', '[]')->get(['customer_id', 'sent_at']);
        $cameBack = $reminded->filter(fn (ServiceReminder $r) => $r->customer_id && Appointment::query()->forOrganization($organization)->where('customer_id', $r->customer_id)
            ->whereBetween('created_at', [$r->sent_at, $r->sent_at->copy()->addDays(OutreachService::RESULT_DAYS)])
            ->where('status', '!=', AppointmentStatus::Cancelled->value)->exists())->count();

        return view('livewire.client.outreach', [
            'services' => $this->services(),
            'tags' => Tag::query()->forOrganization($organization)->orderBy('name')->get(['id', 'name']),
            'groupSize' => (clone $group)->count(),
            'textable' => (clone $group)->where('sms_consent', true)->whereNotNull('phone_e164')->count(),
            'emailable' => (clone $group)->whereNotNull('email')->count(),
            'campaigns' => Campaign::query()->forOrganization($organization)->latest('id')->limit(20)->get()
                ->map(fn (Campaign $c) => ['campaign' => $c, 'booked' => $outreach->bookedAfter($c)]),
            'reminderStats' => ['sent' => $reminded->count(), 'back' => $cameBack],
            'canManage' => auth()->user()->can('organization.update', $organization),
            'timezone' => $organization->timezoneOrDefault(),
        ]);
    }
}
