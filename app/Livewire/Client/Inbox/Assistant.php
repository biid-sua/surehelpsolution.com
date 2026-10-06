<?php

namespace App\Livewire\Client\Inbox;

use App\Enums\InboxChannel;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\AiAssistant;
use App\Models\AiFeedback;
use App\Models\AiGuideline;
use App\Models\AiRun;
use App\Models\Appointment;
use App\Services\Ai\Contracts\AiProvider;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The AI messaging assistant's settings, guidelines and results (spec §26A, D38–D39).
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('AI assistant')]
class Assistant extends Component
{
    use ScopedToOrganization;

    /** @var array<string, mixed> */
    public array $form = [];

    public string $newGuideline = '';

    /** @var array<string, string> */
    public array $guidelineText = [];

    public function mount(): void
    {
        $organization = $this->organization();
        $this->authorize('ai.view', $organization);
        $assistant = AiAssistant::for($organization);

        $this->form = [
            'is_enabled' => $assistant->is_enabled,
            'name' => $assistant->name,
            'tone' => $assistant->tone,
            'modes' => collect(InboxChannel::cases())->mapWithKeys(fn (InboxChannel $c) => [$c->value => (string) ($assistant->modes[$c->value] ?? 'off')])->all(),
            'can_book' => $assistant->can_book,
            'bookings_need_confirmation' => $assistant->bookings_need_confirmation,
            'handover_message' => (string) $assistant->handover_message,
            'instructions' => (string) $assistant->instructions,
        ];
    }

    public function save(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('ai.manage', $organization);

        $this->validate([
            'form.is_enabled' => ['boolean'],
            'form.name' => ['required', 'string', 'max:40'],
            'form.tone' => ['required', Rule::in(array_keys(AiAssistant::TONES))],
            'form.modes.*' => ['required', Rule::in(array_keys(AiAssistant::MODES))],
            'form.can_book' => ['boolean'],
            'form.bookings_need_confirmation' => ['boolean'],
            'form.handover_message' => ['nullable', 'string', 'max:500'],
            'form.instructions' => ['nullable', 'string', 'max:4000'],
        ], [], ['form.name' => 'name', 'form.instructions' => 'instructions']);

        $assistant = AiAssistant::query()->forOrganization($organization)->first() ?? new AiAssistant(['organization_id' => $organization->id]);
        $assistant->fill([
            'is_enabled' => (bool) $this->form['is_enabled'],
            'name' => trim((string) $this->form['name']),
            'tone' => $this->form['tone'],
            'modes' => collect(InboxChannel::cases())->mapWithKeys(fn (InboxChannel $c) => [$c->value => (string) ($this->form['modes'][$c->value] ?? 'off')])->all(),
            'can_book' => (bool) $this->form['can_book'],
            'bookings_need_confirmation' => (bool) $this->form['bookings_need_confirmation'],
            'handover_message' => trim((string) $this->form['handover_message']) ?: null,
            'instructions' => trim((string) $this->form['instructions']) ?: null,
        ])->save();
        $audit->changes('ai.assistant_updated', $assistant, ['is_enabled', 'name', 'tone', 'modes', 'can_book', 'bookings_need_confirmation', 'handover_message', 'instructions']);

        $this->dispatch('toast', type: 'success', message: $assistant->is_enabled ? 'Saved. The assistant uses these settings from the next message.' : 'Saved. The assistant is off.');
    }

    /** Instantly stop every AI reply (spec §38: support disabling AI features). */
    public function stopAll(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('ai.manage', $organization);
        $assistant = AiAssistant::query()->forOrganization($organization)->first();
        if ($assistant) {
            $assistant->forceFill(['is_enabled' => false])->save();
            $audit->record('ai.assistant_stopped', $assistant, organization: $organization, label: 'AI assistant');
        }
        $this->form['is_enabled'] = false;
        $this->dispatch('toast', type: 'success', message: 'The assistant is off. People answer every message now.');
    }

    public function addGuideline(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('ai.manage', $organization);
        $this->validate(['newGuideline' => ['required', 'string', 'max:1000']], ['newGuideline.required' => 'Write the guideline first.']);

        $guideline = AiGuideline::create([
            'organization_id' => $organization->id, 'text' => trim($this->newGuideline), 'status' => 'active',
            'created_by_user_id' => auth()->id(), 'approved_by_user_id' => auth()->id(), 'approved_at' => now(),
        ]);
        $audit->record('ai.guideline_added', $guideline, new: ['text' => $guideline->text], organization: $organization, label: 'AI guideline');
        $this->newGuideline = '';
    }

    public function approveGuideline(string $ulid, Audit $audit): void
    {
        $this->authorize('ai.manage', $this->organization());
        $guideline = $this->guideline($ulid);
        $text = trim((string) ($this->guidelineText[$ulid] ?? $guideline->text));
        if ($text === '') {
            $this->addError('guidelineText.'.$ulid, 'A guideline can\'t be empty.');

            return;
        }
        $guideline->forceFill(['text' => mb_substr($text, 0, 1000), 'status' => 'active', 'approved_by_user_id' => auth()->id(), 'approved_at' => now()])->save();
        $audit->record('ai.guideline_approved', $guideline, new: ['text' => $guideline->text], organization: $this->organization(), label: 'AI guideline');
    }

    public function archiveGuideline(string $ulid, Audit $audit): void
    {
        $this->authorize('ai.manage', $this->organization());
        $guideline = $this->guideline($ulid);
        $guideline->forceFill(['status' => 'archived'])->save();
        $audit->record('ai.guideline_archived', $guideline, old: ['text' => $guideline->text], organization: $this->organization(), label: 'AI guideline');
    }

    private function guideline(string $ulid): AiGuideline
    {
        return AiGuideline::query()->forOrganization($this->organization())->where('ulid', $ulid)->firstOrFail();
    }

    public function render(AiProvider $ai): View
    {
        $organization = $this->organization();
        $since = now()->subDays(30);
        $runs = AiRun::query()->forOrganization($organization)->where('created_at', '>=', $since);
        $feedback = AiFeedback::query()->forOrganization($organization)->where('created_at', '>=', $since);
        $rated = (clone $feedback)->count();
        $guidelines = AiGuideline::query()->forOrganization($organization)->whereIn('status', ['draft', 'active'])->with('creator:id,name')
            ->orderByRaw("CASE status WHEN 'draft' THEN 0 ELSE 1 END")->latest('id')->get();
        foreach ($guidelines as $g) {
            $this->guidelineText[$g->ulid] ??= $g->text;
        }

        return view('livewire.client.inbox.assistant', [
            'configured' => $ai->isConfigured(),
            'canManage' => auth()->user()->can('ai.manage', $organization),
            'channels' => InboxChannel::cases(),
            'modes' => AiAssistant::MODES,
            'tones' => AiAssistant::TONES,
            'guidelines' => $guidelines,
            'stats' => [
                'replied' => (clone $runs)->where('status', 'replied')->count(),
                'drafted' => (clone $runs)->where('status', 'drafted')->count(),
                'handed_over' => (clone $runs)->where('status', 'handed_over')->count(),
                'booked' => Appointment::query()->forOrganization($organization)->where('source', 'ai')->where('created_at', '>=', $since)->count(),
                'helpful' => $rated ? (int) round((clone $feedback)->where('rating', 'helpful')->count() / $rated * 100) : null,
                'rated' => $rated,
            ],
        ]);
    }
}
