<?php

namespace App\Livewire\Client\Customers;

use App\Actions\Customers\EraseCustomer;
use App\Actions\Customers\RecordTimelineEvent;
use App\Actions\Customers\UpdateCustomer;
use App\Enums\CustomerStatus;
use App\Enums\TimelineEventType;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Customer;
use App\Models\Tag;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
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

        $this->form = UpdateCustomer::formFor($c);
        $this->resetValidation();
        $this->editing = true;
    }

    public function save(UpdateCustomer $update): void
    {
        $this->authorize('customers.update', $this->organization());
        $data = $this->validate(collect(UpdateCustomer::rules())->mapWithKeys(fn ($r, $k) => ["form.$k" => $r])->all())['form'];

        try {
            $update->handle($this->customer(), $data, auth()->user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('form.'.$field, $messages[0]);
            }

            return;
        }

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
