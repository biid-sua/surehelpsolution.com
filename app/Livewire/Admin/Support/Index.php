<?php

namespace App\Livewire\Admin\Support;

use App\Enums\SupportTicketStatus;
use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportDesk;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Support queue for SureHelp staff (spec §78): every business's requests, urgent and longest-waiting
 * first. Staff reply (the business is told), assign and change the status.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Support')]
class Index extends Component
{
    use PlatformAdminOnly;
    use WithFileUploads;
    use WithPagination;

    public const VIEWS = ['active' => 'Needs us', 'mine' => 'Assigned to me', 'waiting' => 'Waiting for customer', 'done' => 'Resolved and closed', 'all' => 'All'];

    #[Url(except: 'active')]
    public string $view = 'active';

    #[Url(as: 'ticket', except: '')]
    public string $selected = '';

    #[Url(except: '')]
    public string $search = '';

    public string $reply = '';

    public string $replyStatus = 'waiting_customer';

    /** @var TemporaryUploadedFile|null */
    public $replyFile = null;

    public function mount(): void
    {
        $this->authorize('support.manage');
        $this->view = array_key_exists($this->view, self::VIEWS) ? $this->view : 'active';
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['view', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function open(string $ulid): void
    {
        $this->selected = $ulid;
        $this->reset('reply', 'replyFile');
        $this->replyStatus = SupportTicketStatus::WaitingForCustomer->value;
        $this->resetValidation();
    }

    public function send(SupportDesk $desk): void
    {
        $this->authorize('support.manage');
        $this->validate([
            'reply' => ['required', 'string', 'max:10000'],
            'replyStatus' => ['required', Rule::enum(SupportTicketStatus::class)],
            'replyFile' => SupportDesk::ATTACHMENT_RULES,
        ], attributes: ['replyFile' => 'attachment']);

        $desk->reply($this->ticket(), auth()->user(), $this->reply, true, $this->replyFile, SupportTicketStatus::from($this->replyStatus));
        $this->reset('reply', 'replyFile');
        $this->dispatch('toast', type: 'success', message: 'Reply sent. The business has been told.');
    }

    public function setStatus(string $status, SupportDesk $desk): void
    {
        $this->authorize('support.manage');
        $desk->setStatus($this->ticket(), SupportTicketStatus::tryFrom($status) ?? abort(422), auth()->user());
    }

    public function assignToMe(SupportDesk $desk): void
    {
        $this->authorize('support.manage');
        $desk->assign($this->ticket(), auth()->user(), auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Assigned to you.');
    }

    public function assignTo(int $userId, SupportDesk $desk): void
    {
        $this->authorize('support.manage');
        $desk->assign($this->ticket(), $userId ? User::query()->where('role', 'admin')->findOrFail($userId) : null, auth()->user());
    }

    private function ticket(): SupportTicket
    {
        return SupportTicket::withoutGlobalScopes()->where('ulid', $this->selected)->firstOrFail();
    }

    public function render(SupportDesk $desk): View
    {
        $base = fn () => SupportTicket::withoutGlobalScopes();
        $tickets = $base()->with(['organization:id,ulid,name', 'assignee:id,name'])
            ->tap(fn (Builder $q) => match ($this->view) {
                'mine' => $q->active()->where('assigned_to_user_id', auth()->id()),
                'waiting' => $q->where('status', SupportTicketStatus::WaitingForCustomer->value),
                'done' => $q->whereIn('status', [SupportTicketStatus::Resolved->value, SupportTicketStatus::Closed->value]),
                'all' => $q,
                default => $q->whereIn('status', [SupportTicketStatus::Open->value, SupportTicketStatus::InProgress->value]),
            })
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s
                ->where('subject', 'like', '%'.addcslashes(trim($this->search), '%_\\').'%')
                ->orWhereIn('organization_id', Organization::query()->where('name', 'like', '%'.addcslashes(trim($this->search), '%_\\').'%')->select('id'))
                ->orWhere('id', (int) ltrim(preg_replace('/\D/', '', $this->search) ?: '0', '0'))))
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderBy('last_reply_at')
            ->paginate(25);

        $current = $this->selected !== ''
            ? $base()->where('ulid', $this->selected)->with(['messages.author:id,name', 'openedBy:id,name,email', 'organization:id,ulid,name', 'assignee:id,name'])->first()
            : null;

        return view('livewire.admin.support.index', [
            'tickets' => $tickets,
            'current' => $current,
            'views' => self::VIEWS,
            'counts' => ['active' => $base()->whereIn('status', [SupportTicketStatus::Open->value, SupportTicketStatus::InProgress->value])->count()],
            'statuses' => SupportTicketStatus::cases(),
            'staff' => $current ? $desk->staff() : collect(),
        ]);
    }
}
