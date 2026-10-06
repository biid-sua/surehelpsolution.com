<?php

namespace App\Livewire\Client\Inbox;

use App\Actions\Inbox\RateAiReply;
use App\Actions\Inbox\SendReply;
use App\Enums\InboxChannel;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\AiAssistant;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\Contracts\AiProvider;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The unified inbox (spec §26): every customer conversation, the reply box, the AI's suggestions,
 * hand-over controls and feedback on the AI's replies (§26A, D39).
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Inbox')]
class Index extends Component
{
    use ScopedToOrganization;

    public ?string $selected = null;

    #[Url(except: 'attention')]
    public string $filter = 'attention';

    #[Url(except: '')]
    public string $channel = '';

    #[Url(except: '')]
    public string $search = '';

    public string $reply = '';

    public bool $asNote = false;

    /** @var array<string, string> message ulid => correction text */
    public array $correction = [];

    public ?string $correcting = null;

    public function mount(?string $conversation = null): void
    {
        $this->authorize('messages.view', $this->organization());
        if ($conversation) {
            $this->open($conversation);
        }
    }

    public function open(string $ulid): void
    {
        $conversation = $this->conversation($ulid);
        $this->selected = $conversation->ulid;
        $this->reply = '';
        $this->asNote = false;
        $this->resetValidation();
        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }
    }

    public function send(SendReply $send): void
    {
        $this->authorize('messages.send', $this->organization());
        $conversation = $this->current();

        try {
            $message = $send->handle($conversation, $this->reply, auth()->user(), note: $this->asNote);
        } catch (ValidationException $e) {
            $this->addError('reply', implode(' ', array_merge(...array_values($e->errors()))));

            return;
        }

        if ($message->status === 'failed') {
            $this->addError('reply', (string) $message->error);

            return;
        }

        $this->reply = '';
        $this->asNote = false;
        $conversation->messages()->where('author_type', 'ai')->where('status', 'draft')->update(['status' => 'discarded']);
    }

    /** Send the AI's suggestion as it is (a person approves it by sending). */
    public function sendDraft(string $ulid, SendReply $send): void
    {
        $this->authorize('messages.send', $this->organization());
        $draft = $this->draft($ulid);

        try {
            $sent = $send->handle($this->current(), (string) $draft->body, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('reply', implode(' ', array_merge(...array_values($e->errors()))));

            return;
        }
        $draft->forceFill(['status' => 'used'])->save();
        if ($sent->status === 'failed') {
            $this->addError('reply', (string) $sent->error);
        }
    }

    public function editDraft(string $ulid): void
    {
        $draft = $this->draft($ulid);
        $this->reply = (string) $draft->body;
        $this->asNote = false;
        $draft->forceFill(['status' => 'used'])->save();
    }

    public function discardDraft(string $ulid): void
    {
        $this->draft($ulid)->forceFill(['status' => 'discarded'])->save();
    }

    public function assign(string $userId, Audit $audit): void
    {
        $this->authorize('messages.send', $this->organization());
        $conversation = $this->current();
        $member = $userId === '' ? null : $this->organization()->members()->wherePivot('status', 'active')->whereKey((int) $userId)->first();
        $conversation->forceFill(['assigned_to_user_id' => $member?->id])->save();
        $audit->record('inbox.assigned', $conversation, new: ['assigned_to' => $member?->name], organization: $this->organization(), label: $conversation->displayName());
    }

    public function setStatus(string $status, Audit $audit): void
    {
        $this->authorize('messages.send', $this->organization());
        $conversation = $this->current();
        $closing = $status === 'closed';
        $conversation->forceFill([
            'status' => $closing ? 'closed' : 'open',
            'closed_at' => $closing ? now() : null,
            'needs_human' => $closing ? false : $conversation->needs_human,
            'unread_count' => $closing ? 0 : $conversation->unread_count,
        ])->save();
        $audit->record($closing ? 'inbox.closed' : 'inbox.reopened', $conversation, organization: $this->organization(), label: $conversation->displayName());
    }

    /** Let the assistant answer this conversation again, or keep it for people only. */
    public function setAi(bool $on, Audit $audit): void
    {
        $this->authorize('messages.send', $this->organization());
        $conversation = $this->current();
        $conversation->forceFill(['ai_paused' => ! $on, 'needs_human' => $on ? false : $conversation->needs_human])->save();
        $audit->record($on ? 'inbox.ai_resumed' : 'inbox.ai_paused', $conversation, organization: $this->organization(), label: $conversation->displayName());
    }

    public function rate(string $ulid, string $rating, RateAiReply $rate): void
    {
        $this->authorize('messages.view', $this->organization());
        $message = $this->message($ulid);
        $rate->handle($message, auth()->user(), $rating);
        $this->correcting = $rating === 'unhelpful' ? $ulid : null;
    }

    public function saveCorrection(string $ulid, RateAiReply $rate): void
    {
        $this->authorize('messages.view', $this->organization());
        $this->validate(['correction.'.$ulid => ['required', 'string', 'max:1000']], ['correction.*.required' => 'Say what it should have said.']);
        $rate->handle($this->message($ulid), auth()->user(), 'unhelpful', $this->correction[$ulid]);
        $this->correcting = null;
        unset($this->correction[$ulid]);
        $this->dispatch('toast', type: 'success', message: 'Thanks. It\'s saved as a guideline for the owner to approve in Inbox › AI assistant.');
    }

    private function conversation(string $ulid): Conversation
    {
        return Conversation::query()->forOrganization($this->organization())->where('ulid', $ulid)->firstOrFail();
    }

    private function current(): Conversation
    {
        return $this->conversation((string) $this->selected);
    }

    private function message(string $ulid): Message
    {
        return Message::query()->forOrganization($this->organization())->where('conversation_id', $this->current()->id)->where('ulid', $ulid)->firstOrFail();
    }

    private function draft(string $ulid): Message
    {
        $message = $this->message($ulid);
        abort_unless($message->status === 'draft', 404);

        return $message;
    }

    public function render(AiProvider $ai): View
    {
        $organization = $this->organization();
        $base = Conversation::query()->forOrganization($organization);

        $list = (clone $base)
            ->with(['customer:id,first_name,last_name,company', 'assignee:id,name'])
            ->when(InboxChannel::tryFrom($this->channel), fn (Builder $q, InboxChannel $c) => $q->where('channel', $c))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s
                ->where('contact_name', 'like', '%'.addcslashes(trim($this->search), '%_\\').'%')
                ->orWhere('contact_handle', 'like', '%'.addcslashes(trim($this->search), '%_\\').'%')
                ->orWhereHas('customer', fn (Builder $c) => $c->search(trim($this->search)))))
            ->tap(fn (Builder $q) => match ($this->filter) {
                'ai' => $q->where('status', 'open')->where('needs_human', false)->where('ai_paused', false),
                'open' => $q->where('status', 'open'),
                'closed' => $q->where('status', 'closed'),
                'all' => $q,
                default => $q->where('status', 'open')->where('needs_human', true),
            })
            ->orderByDesc('last_message_at')->orderByDesc('id')->limit(100)->get();

        $current = $this->selected ? (clone $base)->where('ulid', $this->selected)->with(['customer', 'assignee'])->first() : null;
        $messages = $current
            ? $current->messages()->whereNotIn('status', ['discarded', 'used'])->with(['author:id,name', 'aiRun', 'feedback' => fn ($q) => $q->where('user_id', auth()->id())])->get()
            : collect();
        $assistant = AiAssistant::for($organization);
        $timezone = $organization->timezoneOrDefault();

        return view('livewire.client.inbox.index', [
            'conversations' => $list,
            'current' => $current,
            'messages' => $messages,
            'counts' => [
                'attention' => (clone $base)->where('status', 'open')->where('needs_human', true)->count(),
                'ai' => (clone $base)->where('status', 'open')->where('needs_human', false)->where('ai_paused', false)->count(),
            ],
            'channels' => InboxChannel::cases(),
            'members' => $organization->members()->wherePivot('status', 'active')->orderBy('name')->get(['users.id', 'users.name']),
            'window' => $current?->channel->replyWindow($current->last_inbound_at, true),
            'aiMode' => $current ? ($ai->isConfigured() ? $assistant->modeFor($current->channel) : 'off') : 'off',
            'canSend' => auth()->user()->can('messages.send', $organization),
            'timezone' => $timezone,
        ]);
    }
}
