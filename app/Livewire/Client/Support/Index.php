<?php

namespace App\Livewire\Client\Support;

use App\Enums\SupportTicketStatus;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\SupportTicket;
use App\Services\Support\SupportDesk;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Support (spec §78, CLI-09): the business asks SureHelp for help, follows its requests and replies.
 * "Request a script change" opens the form ready to fill in.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Support')]
class Index extends Component
{
    use ScopedToOrganization;
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'ticket', except: '')]
    public string $selected = '';

    public bool $creating = false;

    /** @var array{subject: string, category: string, priority: string, body: string} */
    public array $form = ['subject' => '', 'category' => 'question', 'priority' => 'normal', 'body' => ''];

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public string $reply = '';

    /** @var TemporaryUploadedFile|null */
    public $replyFile = null;

    public function mount(): void
    {
        $this->authorize('support.view', $this->organization());
        if (request()->query('new') === 'script') {
            $this->startNew('script_change');
        }
    }

    public function startNew(string $category = 'question'): void
    {
        $this->authorize('support.create', $this->organization());
        $this->form = ['subject' => $category === 'script_change' ? 'Script change request' : '', 'category' => array_key_exists($category, SupportTicket::CATEGORIES) ? $category : 'question', 'priority' => 'normal',
            'body' => $category === 'script_change' ? "What should our agents say or do differently?\n\n" : ''];
        $this->file = null;
        $this->resetValidation();
        $this->creating = true;
        $this->selected = '';
    }

    public function create(SupportDesk $desk): void
    {
        $organization = $this->organization();
        $this->authorize('support.create', $organization);
        $this->validate([
            'form.subject' => ['required', 'string', 'max:200'],
            'form.category' => ['required', Rule::in(array_keys(SupportTicket::CATEGORIES))],
            'form.priority' => ['required', Rule::in(array_keys(SupportTicket::PRIORITIES))],
            'form.body' => ['required', 'string', 'max:10000'],
            'file' => SupportDesk::ATTACHMENT_RULES,
        ], attributes: ['form.subject' => 'subject', 'form.body' => 'message', 'file' => 'attachment']);

        $ticket = $desk->open($organization, auth()->user(), $this->form, $this->file);
        $this->creating = false;
        $this->file = null;
        $this->selected = $ticket->ulid;
        $this->dispatch('toast', type: 'success', message: 'Sent. We\'ll reply here and by email ('.$ticket->reference().').');
    }

    public function send(SupportDesk $desk): void
    {
        $this->authorize('support.create', $this->organization());
        $this->validate(['reply' => ['required', 'string', 'max:10000'], 'replyFile' => SupportDesk::ATTACHMENT_RULES], attributes: ['replyFile' => 'attachment']);

        try {
            $desk->reply($this->ticket(), auth()->user(), $this->reply, false, $this->replyFile);
        } catch (ValidationException $e) {
            $this->addError('reply', (string) collect($e->errors())->flatten()->first());

            return;
        }
        $this->reset('reply', 'replyFile');
        $this->dispatch('toast', type: 'success', message: 'Reply sent.');
    }

    public function close(SupportDesk $desk): void
    {
        $this->authorize('support.create', $this->organization());
        $desk->setStatus($this->ticket(), SupportTicketStatus::Closed, auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Request closed.');
    }

    private function ticket(): SupportTicket
    {
        return SupportTicket::query()->forOrganization($this->organization())->where('ulid', $this->selected)->firstOrFail();
    }

    public function render(): View
    {
        $organization = $this->organization();
        $current = $this->selected !== ''
            ? SupportTicket::query()->forOrganization($organization)->where('ulid', $this->selected)->with(['messages.author:id,name', 'openedBy:id,name'])->first()
            : null;

        return view('livewire.client.support.index', [
            'tickets' => SupportTicket::query()->forOrganization($organization)->with('openedBy:id,name')
                ->orderByRaw('CASE WHEN status IN (?, ?, ?) THEN 0 ELSE 1 END', SupportTicketStatus::activeValues())
                ->latest('last_reply_at')->paginate(15),
            'current' => $current,
            'categories' => SupportTicket::CATEGORIES,
            'priorities' => SupportTicket::PRIORITIES,
            'canCreate' => auth()->user()->can('support.create', $organization),
        ]);
    }
}
