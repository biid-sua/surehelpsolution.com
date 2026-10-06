<?php

namespace App\Livewire\Client\Inbox;

use App\Enums\SocialNetwork;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\ChatWidget;
use App\Models\SocialAccount;
use App\Services\Inbox\MetaMessaging;
use App\Services\Social\SocialManager;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Where messages come from (D37): the website chat widget and Messenger / Instagram.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Inbox channels')]
class Channels extends Component
{
    use ScopedToOrganization;

    /** @var array<string, mixed> */
    public array $widget = [];

    public function mount(): void
    {
        $organization = $this->organization();
        $this->authorize('messages.view', $organization);
        $widget = ChatWidget::for($organization);

        $this->widget = [
            'is_enabled' => $widget->is_enabled,
            'title' => $widget->title,
            'greeting' => (string) $widget->greeting,
            'color' => $widget->color,
            'allowed_origins' => implode("\n", (array) $widget->allowed_origins),
        ];
    }

    public function saveWidget(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('integrations.manage', $organization);

        $this->validate([
            'widget.is_enabled' => ['boolean'],
            'widget.title' => ['required', 'string', 'max:60'],
            'widget.greeting' => ['nullable', 'string', 'max:300'],
            'widget.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'widget.allowed_origins' => ['nullable', 'string', 'max:2000'],
        ], ['widget.color.regex' => 'Use a colour like #7C3AED.'], ['widget.title' => 'title']);

        $origins = collect(preg_split('/[\s,]+/', (string) $this->widget['allowed_origins']) ?: [])
            ->map(fn ($o) => strtolower(trim((string) $o)))->filter()
            ->map(fn ($o) => (string) (parse_url(str_contains($o, '://') ? $o : 'https://'.$o, PHP_URL_HOST) ?: $o))
            ->unique()->values()->all();

        $widget = ChatWidget::for($organization);
        $widget->fill([
            'is_enabled' => (bool) $this->widget['is_enabled'],
            'title' => trim((string) $this->widget['title']),
            'greeting' => trim((string) $this->widget['greeting']) ?: null,
            'color' => $this->widget['color'],
            'allowed_origins' => $origins ?: null,
        ])->save();
        $audit->changes('inbox.widget_updated', $widget, ['is_enabled', 'title', 'greeting', 'color', 'allowed_origins']);

        $this->widget['allowed_origins'] = implode("\n", $origins);
        $this->dispatch('toast', type: 'success', message: 'Website chat saved.');
    }

    public function toggleMessaging(string $ulid, MetaMessaging $messaging): void
    {
        $organization = $this->organization();
        $this->authorize('integrations.manage', $organization);
        $account = SocialAccount::query()->forOrganization($organization)->where('ulid', $ulid)
            ->whereIn('network', [SocialNetwork::Facebook->value, SocialNetwork::Instagram->value])->firstOrFail();

        try {
            $account->messaging_enabled ? $messaging->disable($account) : $messaging->enable($account);
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: implode(' ', array_merge(...array_values($e->errors()))));
        }
    }

    public function render(SocialManager $social): View
    {
        $organization = $this->organization();

        return view('livewire.client.inbox.channels', [
            'chat' => ChatWidget::for($organization),
            'meta' => $social->connector('meta'),
            'accounts' => SocialAccount::query()->forOrganization($organization)
                ->whereIn('network', [SocialNetwork::Facebook->value, SocialNetwork::Instagram->value])->orderBy('network')->orderBy('name')->get(),
            'canManage' => auth()->user()->can('integrations.manage', $organization),
        ]);
    }
}
