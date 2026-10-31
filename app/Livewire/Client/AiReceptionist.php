<?php

namespace App\Livewire\Client;

use App\Enums\InboxChannel;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\BusinessProfile;
use App\Models\Conversation;
use App\Models\PhoneNumber;
use App\Models\VoiceCall;
use App\Models\VoiceReceptionist;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Telephony\AiReceptionist as Receptionist;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The AI receptionist's settings (D108): when it answers, what it says first, its voice and what it
 * may do, and a practice call by typing, which never books or saves anything.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('AI receptionist')]
class AiReceptionist extends Component
{
    use ScopedToOrganization;

    /** @var array{mode: string, greeting: string, voice: string, can_book: bool, transfer_to_owner: bool, max_minutes: int|string} */
    public array $form = ['mode' => 'off', 'greeting' => '', 'voice' => 'Polly.Joanna-Neural', 'can_book' => true, 'transfer_to_owner' => true, 'max_minutes' => 8];

    /** @var list<array{role: string, text: string}> the practice call so far */
    public array $practice = [];

    public string $practiceInput = '';

    public bool $practiceEnded = false;

    public function mount(): void
    {
        $this->authorize('ai.view', $this->organization());
        $settings = VoiceReceptionist::for($this->organization());
        $this->form = [
            'mode' => $settings->mode, 'greeting' => (string) $settings->greeting, 'voice' => $settings->voiceName(),
            'can_book' => $settings->can_book, 'transfer_to_owner' => $settings->transfer_to_owner, 'max_minutes' => $settings->max_minutes,
        ];
        $this->restartPractice();
    }

    public function save(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('ai.manage', $organization);
        $data = $this->validate($this->rules(), [
            'form.greeting.regex' => 'Say in the greeting that callers are talking to a virtual or AI assistant.',
        ])['form'];

        $settings = VoiceReceptionist::for($organization);
        $settings->fill([
            'mode' => $data['mode'], 'greeting' => trim((string) $data['greeting']) ?: null, 'voice' => $data['voice'],
            'can_book' => (bool) $data['can_book'], 'transfer_to_owner' => (bool) $data['transfer_to_owner'], 'max_minutes' => (int) $data['max_minutes'],
        ]);
        $dirty = array_keys($settings->getDirty());
        $settings->save();
        if ($dirty !== []) {
            $audit->changes('ai_receptionist.updated', $settings, $dirty, $organization);
        }

        $this->dispatch('toast', type: 'success', message: $data['mode'] === 'off' ? 'Saved. The AI receptionist is off.' : 'Saved. The AI receptionist is on.');
    }

    /** A practice call by typing: the same brain and voice rules as a real call, but nothing is done. */
    public function sendPractice(Receptionist $receptionist, AiProvider $ai): void
    {
        $organization = $this->organization();
        $this->authorize('ai.manage', $organization);
        $text = trim($this->practiceInput);
        if ($text === '' || $this->practiceEnded) {
            return;
        }
        if (! $ai->isConfigured()) {
            $this->addError('practiceInput', 'The AI isn\'t connected yet. Ask SureHelp to set it up.');

            return;
        }
        if (RateLimiter::tooManyAttempts($key = 'ai-receptionist-practice:'.$organization->id, 40)) {
            $this->addError('practiceInput', 'That\'s a lot of practice for one hour. Please try again a little later.');

            return;
        }
        RateLimiter::hit($key, 3600);
        $this->validate($this->rules(), ['form.greeting.regex' => 'Say in the greeting that callers are talking to a virtual or AI assistant.']);

        $this->practice[] = ['role' => 'user', 'text' => mb_substr($text, 0, 500)];
        $this->practiceInput = '';
        $history = [];
        foreach ($this->practice as $turn) {
            if ($history === [] && $turn['role'] === 'assistant') {
                continue; // the greeting: the model's history starts with the caller
            }
            $history[] = ['role' => $turn['role'], 'content' => $turn['text']];
        }

        $conversation = new Conversation(['organization_id' => $organization->id, 'channel' => InboxChannel::Phone, 'contact_handle' => 'Practice call']);
        $conversation->setRelation('organization', $organization);
        $conversation->setRelation('customer', null);
        $reply = $receptionist->respond($organization, $conversation, $this->settings(), $history, '', $this->canTransfer(), practice: true);

        $this->practice[] = ['role' => 'assistant', 'text' => $reply['text'].($reply['actions'] ? ' ('.implode(', ', array_map(fn ($a) => str_replace('_', ' ', $a), $reply['actions'])).': practice only)' : '')];
        $this->practiceEnded = $reply['end'] || $reply['transfer'];
    }

    public function restartPractice(): void
    {
        $this->practice = [['role' => 'assistant', 'text' => $this->settings()->greetingFor($this->businessName())]];
        $this->practiceEnded = false;
        $this->practiceInput = '';
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'form.mode' => ['required', Rule::in(array_keys(VoiceReceptionist::MODES))],
            // Callers must know they're talking to an AI (several US states require it).
            'form.greeting' => ['nullable', 'string', 'max:400', 'regex:/\b(virtual|AI|automated|assistant)\b/i'],
            'form.voice' => ['required', Rule::in(array_keys(VoiceReceptionist::VOICES))],
            'form.can_book' => ['boolean'],
            'form.transfer_to_owner' => ['boolean'],
            'form.max_minutes' => ['required', 'integer', 'between:2,20'],
        ];
    }

    /** The settings as typed (not yet saved), for the practice call. */
    private function settings(): VoiceReceptionist
    {
        return new VoiceReceptionist([
            'organization_id' => $this->organization()->id, 'mode' => $this->form['mode'], 'greeting' => $this->form['greeting'] ?: null,
            'voice' => $this->form['voice'], 'can_book' => (bool) $this->form['can_book'], 'transfer_to_owner' => (bool) $this->form['transfer_to_owner'],
            'max_minutes' => (int) $this->form['max_minutes'],
        ]);
    }

    private function canTransfer(): bool
    {
        return (bool) $this->form['transfer_to_owner'] && PhoneNumber::query()->forOrganization($this->organization())->active()->whereNotNull('transfer_number')->exists();
    }

    private function businessName(): string
    {
        return BusinessProfile::query()->forOrganization($this->organization())->value('display_name') ?: $this->organization()->name;
    }

    public function render(AiProvider $ai): View
    {
        $organization = $this->organization();
        $lines = PhoneNumber::query()->forOrganization($organization)->active()->get(['id', 'number', 'transfer_number']);

        return view('livewire.client.ai-receptionist', [
            'modes' => VoiceReceptionist::MODES,
            'voices' => VoiceReceptionist::VOICES,
            'aiReady' => $ai->isConfigured(),
            'lines' => $lines,
            'canManage' => auth()->user()->can('ai.manage', $organization),
            'recent' => VoiceCall::query()->forOrganization($organization)->whereNotNull('ai_conversation_id')->latest('id')->limit(8)
                ->with('aiConversation:id,ulid')->get(['id', 'ai_conversation_id', 'from_number', 'status', 'started_at', 'duration_seconds', 'ai_turns', 'customer_id']),
            'timezone' => $organization->timezoneOrDefault(),
        ]);
    }
}
