<?php

namespace App\Livewire\Admin\Enquiries;

use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\ContactSubmission;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Messages from the website's contact form: sales leads, demo requests, support. Open ones first,
 * marked handled once someone has replied.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Website enquiries')]
class Index extends Component
{
    use PlatformAdminOnly;
    use WithPagination;

    #[Url(except: 'open')]
    public string $show = 'open';

    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'enquiry', except: null)]
    public ?int $selected = null;

    public function mount(): void
    {
        $this->authorize('marketing.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['show', 'kind', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function select(int $id): void
    {
        $this->selected = ContactSubmission::findOrFail($id)->id;
    }

    public function toggleHandled(Audit $audit): void
    {
        $this->authorize('marketing.manage');
        $enquiry = ContactSubmission::findOrFail($this->selected);
        $handled = $enquiry->handled_at === null;
        $enquiry->forceFill(['handled_at' => $handled ? now() : null, 'handled_by' => $handled ? auth()->id() : null])->save();
        $audit->record($handled ? 'enquiry.handled' : 'enquiry.reopened', $enquiry, label: $enquiry->name);
        $this->dispatch('toast', type: 'success', message: $handled ? 'Marked as handled.' : 'Moved back to open.');
    }

    public function render(): View
    {
        $enquiries = ContactSubmission::query()
            ->when($this->show === 'open', fn (Builder $q) => $q->whereNull('handled_at'))
            ->when($this->show === 'handled', fn (Builder $q) => $q->whereNotNull('handled_at'))
            ->when(array_key_exists($this->kind, ContactSubmission::INQUIRY_TYPES), fn (Builder $q) => $q->where('inquiry_type', $this->kind))
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.addcslashes(trim($this->search), '%_\\').'%';
                $query->where(fn (Builder $q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term)
                    ->orWhere('company', 'like', $term)->orWhere('phone', 'like', $term));
            })
            ->latest()->latest('id')
            ->paginate(20);

        return view('livewire.admin.enquiries.index', [
            'enquiries' => $enquiries,
            'current' => $this->selected ? ContactSubmission::with('handler:id,name')->find($this->selected) : null,
            'openCount' => ContactSubmission::whereNull('handled_at')->count(),
            'kinds' => ContactSubmission::INQUIRY_TYPES,
            'canManage' => auth()->user()->hasPermissionIn('marketing.manage'),
        ]);
    }
}
