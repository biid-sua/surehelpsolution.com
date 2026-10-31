<?php

namespace App\Livewire\Client\Documents;

use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\JobDocument;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Estimates & invoices (D115): what was sent, what's waiting on the customer, and what's unpaid. */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Estimates & invoices')]
class Index extends Component
{
    use ScopedToOrganization, WithPagination;

    #[Url]
    public string $type = JobDocument::ESTIMATE;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('payments.view', $this->organization());
        $this->type = in_array($this->type, [JobDocument::ESTIMATE, JobDocument::INVOICE], true) ? $this->type : JobDocument::ESTIMATE;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'status', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $organization = $this->organization();
        $type = in_array($this->type, [JobDocument::ESTIMATE, JobDocument::INVOICE], true) ? $this->type : JobDocument::ESTIMATE;
        $base = JobDocument::query()->forOrganization($organization);
        $documents = (clone $base)->where('type', $type)->with('customer:id,first_name,last_name,company,phone,phone_e164')
            ->when(array_key_exists($this->status, JobDocument::STATUS_LABELS), fn ($q) => $q->where('status', $this->status))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('number', 'like', '%'.trim($this->search).'%')
                ->orWhere('title', 'like', '%'.trim($this->search).'%')
                ->orWhereHas('customer', fn ($c) => $c->search(trim($this->search)))))
            ->latest('id')->paginate(20);

        return view('livewire.client.documents.index', [
            'documents' => $documents,
            'stats' => [
                'waiting' => (clone $base)->where('type', JobDocument::ESTIMATE)->where('status', JobDocument::SENT)->sum('total_cents'),
                'won' => (clone $base)->where('type', JobDocument::ESTIMATE)->whereIn('status', [JobDocument::ACCEPTED, JobDocument::INVOICED])
                    ->where('accepted_at', '>=', now($organization->timezoneOrDefault())->startOfMonth()->utc())->sum('total_cents'),
                'unpaid' => (clone $base)->where('type', JobDocument::INVOICE)->where('status', JobDocument::SENT)->sum('total_cents'),
            ],
            'canWrite' => auth()->user()->can('payments.request', $organization),
            'statuses' => $type === JobDocument::ESTIMATE
                ? array_intersect_key(JobDocument::STATUS_LABELS, array_flip(['draft', 'sent', 'accepted', 'declined', 'invoiced', 'void']))
                : array_intersect_key(JobDocument::STATUS_LABELS, array_flip(['draft', 'sent', 'paid', 'void'])),
        ]);
    }
}
