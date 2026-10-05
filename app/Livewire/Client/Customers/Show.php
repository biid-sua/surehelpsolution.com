<?php

namespace App\Livewire\Client\Customers;

use App\Actions\Customers\EraseCustomer;
use App\Actions\Customers\RecordTimelineEvent;
use App\Enums\CustomerStatus;
use App\Enums\TimelineEventType;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Customer;
use App\Models\Tag;
use App\Support\Audit\Audit;
use App\Support\Phone;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Customer detail and timeline (spec §12–13).
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
class Show extends Component
{
    use ScopedToOrganization;

    #[Locked]
    public string $ulid;

    public bool $editing = false;

    /** @var array<string, mixed> */
    public array $form = [];

    public string $note = '';

    public string $newTag = '';

    public int $timelineLimit = 20;

    public function mount(string $customer): void
    {
        $this->ulid = $customer;
        $this->authorize('customers.view', $this->organization());
        $this->customer(); // 404 for unknown or other businesses' customers
    }

    /**
     * Looked up inside the client's organization only: another business's ID is a 404.
     */
    private function customer(): Customer
    {
        return Customer::query()->forOrganization($this->organization())->where('ulid', $this->ulid)->firstOrFail();
    }

    /** The customer asked for their personal data to be deleted (spec §57). */
    public function erase(EraseCustomer $erase): mixed
    {
        $this->authorize('customers.delete', $this->organization());
        $erase->handle($this->customer(), auth()->user());
        session()->flash('status', 'The customer\'s personal data was erased.');

        return $this->redirectRoute('app.customers.index');
    }

    public function edit(): void
    {
        $this->authorize('customers.update', $this->organization());
        $c = $this->customer();

        $this->form = [
            'first_name' => (string) $c->first_name, 'last_name' => (string) $c->last_name, 'company' => (string) $c->company,
            'phone' => (string) $c->phone, 'email' => (string) $c->email,
            'address_line1' => (string) $c->address_line1, 'address_line2' => (string) $c->address_line2,
            'city' => (string) $c->city, 'state' => (string) $c->state, 'postal_code' => (string) $c->postal_code,
            'status' => $c->status->value, 'preferred_contact' => (string) $c->preferred_contact,
            'sms_consent' => $c->sms_consent, 'email_consent' => $c->email_consent, 'notes' => (string) $c->notes,
        ];
        $this->resetValidation();
        $this->editing = true;
    }

    public function save(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('customers.update', $organization);
        $customer = $this->customer();

        $data = $this->validate([
            'form.first_name' => ['nullable', 'string', 'max:100'],
            'form.last_name' => ['nullable', 'string', 'max:100'],
            'form.company' => ['nullable', 'string', 'max:255'],
            'form.phone' => ['nullable', 'string', 'max:40'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.address_line1' => ['nullable', 'string', 'max:255'],
            'form.address_line2' => ['nullable', 'string', 'max:255'],
            'form.city' => ['nullable', 'string', 'max:100'],
            'form.state' => ['nullable', 'string', 'max:100'],
            'form.postal_code' => ['nullable', 'string', 'max:20'],
            'form.status' => ['required', Rule::enum(CustomerStatus::class)],
            'form.preferred_contact' => ['nullable', Rule::in(array_keys(Customer::CONTACT_METHODS))],
            'form.sms_consent' => ['boolean'],
            'form.email_consent' => ['boolean'],
            'form.notes' => ['nullable', 'string', 'max:5000'],
        ])['form'];

        $e164 = Phone::normalize($data['phone'] ?? null);
        if (filled($data['phone'] ?? null) && $e164 === null) {
            $this->addError('form.phone', 'This doesn\'t look like a valid phone number.');

            return;
        }
        if ($e164 && Customer::withTrashed()->forOrganization($organization)->where('phone_e164', $e164)->whereKeyNot($customer->id)->exists()) {
            $this->addError('form.phone', 'Another customer already has this number.');

            return;
        }

        $value = fn (string $key) => filled($data[$key] ?? null) ? trim((string) $data[$key]) : null;
        $customer->fill([
            'first_name' => $value('first_name'), 'last_name' => $value('last_name'), 'company' => $value('company'),
            'phone' => $value('phone'), 'email' => $value('email'),
            'address_line1' => $value('address_line1'), 'address_line2' => $value('address_line2'),
            'city' => $value('city'), 'state' => $value('state'), 'postal_code' => $value('postal_code'),
            'status' => $data['status'], 'preferred_contact' => $value('preferred_contact'), 'notes' => $value('notes'),
        ]);

        // Consent changes keep a timestamp and a source (spec §57).
        foreach (['sms', 'email'] as $channel) {
            $given = (bool) ($data["{$channel}_consent"] ?? false);
            if ($given !== (bool) $customer->{"{$channel}_consent"}) {
                $customer->{"{$channel}_consent"} = $given;
                $customer->{"{$channel}_consent_at"} = $given ? now() : null;
                $customer->consent_source = 'recorded by '.auth()->user()->name;
            }
        }

        $customer->save();
        $audit->changes('customer.updated', $customer);

        $this->editing = false;
        $this->dispatch('toast', type: 'success', message: 'Customer updated.');
    }

    public function addNote(RecordTimelineEvent $timeline): void
    {
        $this->authorize('customers.update', $this->organization());
        $this->validate(['note' => ['required', 'string', 'max:5000']], attributes: ['note' => 'note']);

        $timeline->handle($this->customer(), TimelineEventType::NoteAdded, 'Note added', trim($this->note), actorId: auth()->id());

        $this->reset('note');
        $this->dispatch('toast', type: 'success', message: 'Note added.');
    }

    public function addTag(): void
    {
        $organization = $this->organization();
        $this->authorize('customers.update', $organization);
        $this->validate(['newTag' => ['required', 'string', 'max:50']], attributes: ['newTag' => 'tag']);

        $tag = Tag::firstOrCreate(['organization_id' => $organization->id, 'name' => trim($this->newTag)]);
        $this->customer()->tags()->syncWithoutDetaching([$tag->id]);
        $this->reset('newTag');
    }

    public function removeTag(int $tagId): void
    {
        $this->authorize('customers.update', $this->organization());
        $this->customer()->tags()->detach($tagId);
    }

    public function loadMore(): void
    {
        $this->timelineLimit += 20;
    }

    public function render(): View
    {
        $organization = $this->organization();
        $customer = $this->customer()->load('tags');
        $events = $customer->timeline()->with('actor:id,name')->limit($this->timelineLimit + 1)->get();

        return view('livewire.client.customers.show', [
            'customer' => $customer,
            'events' => $events->take($this->timelineLimit),
            'hasMore' => $events->count() > $this->timelineLimit,
            'stats' => [
                'calls' => $customer->calls()->count(),
                'first_seen' => $customer->created_at,
            ],
            'statuses' => CustomerStatus::cases(),
            'contactMethods' => Customer::CONTACT_METHODS,
            'allTags' => Tag::query()->forOrganization($organization)->orderBy('name')->pluck('name'),
            'canUpdate' => auth()->user()->can('customers.update', $organization),
            'openTasks' => auth()->user()->can('tasks.view', $organization)
                ? $customer->tasks()->open()->byUrgency()->limit(5)->get()
                : null,
            'canCreateTask' => auth()->user()->can('tasks.create', $organization),
            'appointments' => auth()->user()->can('appointments.view', $organization)
                ? $customer->appointments()->blocking()->where('ends_at', '>=', now())->orderBy('starts_at')->limit(5)->get()
                : null,
            'canBook' => auth()->user()->can('appointments.create', $organization),
            'timezone' => $organization->timezoneOrDefault(),
        ])->title($customer->fullName());
    }
}
