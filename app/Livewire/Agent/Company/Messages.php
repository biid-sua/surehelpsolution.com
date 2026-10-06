<?php

namespace App\Livewire\Agent\Company;

use App\Actions\Inbox\SendReply;
use App\Livewire\Concerns\InAgentCompany;
use App\Models\Conversation;
use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One company's inbox for its assigned agents (spec §26, §20A): the open conversations, one thread at a
 * time, and replies under the same channel rules as the business's own team.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Messages')]
class Messages extends Component
{
    use InAgentCompany;

    public ?string $selected = null;

    public string $reply = '';

    public function mount(Organization $organization, ?string $conversation = null): void
    {
        $this->enterCompany($organization, 'messages.view');
        if ($conversation) {
            $this->open($conversation);
        }
    }

    public function open(string $ulid): void
    {
        $this->selected = $this->conversation($ulid)->ulid; // 404 for anything outside this company
        $this->reply = '';
        $this->resetValidation();
    }

    public function send(SendReply $send): void
    {
        $company = $this->company('messages.send');
        $conversation = $this->conversation((string) $this->selected, $company);

        try {
            $message = $send->handle($conversation, $this->reply, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('reply', implode(' ', array_merge(...array_values($e->errors()))));

            return;
        }
        if ($message->status === 'failed') {
            $this->addError('reply', (string) $message->error);

            return;
        }
        $this->reply = '';
    }

    private function conversation(string $ulid, ?Organization $company = null): Conversation
    {
        return Conversation::query()->forOrganization($company ?? $this->company('messages.view'))->where('ulid', $ulid)->firstOrFail();
    }

    public function render(): View
    {
        $company = $this->company('messages.view');
        $current = $this->selected ? Conversation::query()->forOrganization($company)->where('ulid', $this->selected)->first() : null;

        return view('livewire.agent.company.messages', [
            'company' => $company,
            'conversations' => Conversation::query()->forOrganization($company)->where('status', 'open')->orderByDesc('needs_human')->orderByDesc('last_message_at')->limit(50)->get(),
            'current' => $current,
            'messages' => $current ? $current->messages()->where('is_note', false)->whereIn('status', ['received', 'sent', 'pending', 'failed'])->with('author:id,name')->get() : collect(),
            'window' => $current?->channel->replyWindow($current->last_inbound_at, true),
            'canSend' => auth()->user()->hasPermissionIn('messages.send', $company),
            'timezone' => $company->timezoneOrDefault(),
        ]);
    }
}
