<?php

namespace App\Livewire\Admin\Enquiries;

use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\ContactSubmission;
use App\Services\Sales\SalesChatDesk;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Website enquiries: the contact form, and leads from the sales chat with their transcript, score and
 * booked demo (D78). Open ones first, marked handled once someone has replied. The team can answer a
 * chat right here.
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

    #[Url(except: '')]
    public string $source = '';

    #[Url(as: 'enquiry', except: null)]
    public ?int $selected = null;

    /** A reply typed into a chat lead's transcript (D78). */
    public string $reply = '';

    public function mount(): void
    {
        $this->authorize('marketing.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['show', 'kind', 'search', 'source'], true)) {
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

    /** Answer the visitor in the website chat; they see it live, or by email if they've left. */
    public function sendReply(SalesChatDesk $desk, Audit $audit): void
    {
        $this->authorize('marketing.manage');
        $this->validate(['reply' => ['required', 'string', 'max:2000']]);
        $chat = ContactSubmission::findOrFail($this->selected)->chat ?? abort(404);
        $desk->teamReply($chat, auth()->user(), trim($this->reply));
        $audit->record('enquiry.chat_reply', $chat->enquiry, label: $chat->enquiry->name);
        $this->reset('reply');
        $this->dispatch('toast', type: 'success', message: 'Reply sent.');
    }

    /** Let the assistant answer this chat again. */
    public function handBack(): void
    {
        $this->authorize('marketing.manage');
        $chat = ContactSubmission::findOrFail($this->selected)->chat ?? abort(404);
        $chat->forceFill(['staff_user_id' => null, 'needs_human' => false])->save();
        $this->dispatch('toast', type: 'success', message: 'The assistant is answering this chat again.');
    }

    public function render(): View
    {
        $enquiries = ContactSubmission::query()
            ->when($this->show === 'open', fn (Builder $q) => $q->whereNull('handled_at'))
            ->when($this->show === 'handled', fn (Builder $q) => $q->whereNotNull('handled_at'))
            ->when(array_key_exists($this->kind, ContactSubmission::INQUIRY_TYPES), fn (Builder $q) => $q->where('inquiry_type', $this->kind))
            ->when(array_key_exists($this->source, ContactSubmission::SOURCES), fn (Builder $q) => $q->where('source', $this->source))
            ->when($this->search !== '', function (Builder $query) {
                $term = '%'.addcslashes(trim($this->search), '%_\\').'%';
                $query->where(fn (Builder $q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term)
                    ->orWhere('company', 'like', $term)->orWhere('phone', 'like', $term));
            })
            ->latest()->latest('id')
            ->paginate(20);

        return view('livewire.admin.enquiries.index', [
            'enquiries' => $enquiries,
            'current' => $current = $this->selected ? ContactSubmission::with(['handler:id,name', 'chat.messages.user:id,name'])->find($this->selected) : null,
            'transcript' => $current?->chat?->messages ?? collect(),
            'openCount' => ContactSubmission::whereNull('handled_at')->count(),
            'kinds' => ContactSubmission::INQUIRY_TYPES,
            'sources' => ContactSubmission::SOURCES,
            'canManage' => auth()->user()->hasPermissionIn('marketing.manage'),
        ]);
    }
}
