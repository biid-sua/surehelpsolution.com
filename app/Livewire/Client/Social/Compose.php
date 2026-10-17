<?php

namespace App\Livewire\Client\Social;

use App\Actions\Social\PostWorkflow;
use App\Actions\Social\SavePost;
use App\Actions\Social\StoreMedia;
use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\BusinessProfile;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Growth\LinkPage;
use App\Services\Growth\Openings;
use App\Services\Social\Ai\ContentStudio;
use App\Services\Social\Ai\ContentTokens;
use App\Services\Social\PostValidator;
use App\Services\Social\Publishers\GoogleBusinessPublisher;
use App\Services\Social\SocialApproval;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Write, schedule, approve and follow one post (spec §41): one text for every account, with an
 * optional version per account, photos, the Google button, live checks per network and previews.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Social post')]
class Compose extends Component
{
    use ScopedToOrganization, WithFileUploads;

    public ?string $postUlid = null;

    public string $body = '';

    public string $link = '';

    /** @var array<int, string> account ulids (from the browser: not guaranteed to be a list) */
    public array $selected = [];

    /** @var array<string, bool> */
    public array $customOn = [];

    /** @var array<string, string> */
    public array $custom = [];

    /** @var array<string, array<string, string>> Google Business Profile options per account */
    public array $gbp = [];

    /** @var array<int, string> media ulids in order (from the browser: not guaranteed to be a list) */
    public array $media = [];

    /** @var list<TemporaryUploadedFile> */
    public array $uploads = [];

    public bool $pickingMedia = false;

    public string $date = '';

    public string $time = '';

    public string $changesNote = '';

    /** AI content studio (D66) */
    public string $aiBrief = '';

    public string $aiTone = 'friendly';

    /** @var list<string> things a person should check before publishing */
    public array $aiFlags = [];

    public bool $aiAssisted = false;

    public ?string $aiNote = null;

    public function mount(?string $post = null): void
    {
        $organization = $this->organization();
        $this->authorize('social.view', $organization);
        $timezone = $organization->timezoneOrDefault();

        if ($post) {
            $model = SocialPost::query()->forOrganization($organization)->where('ulid', $post)->with(['targets.account', 'media'])->firstOrFail();
            $this->postUlid = $model->ulid;
            $this->body = $model->body;
            $this->link = (string) $model->link_url;
            $this->media = $model->media->pluck('ulid')->all();
            $this->aiAssisted = $model->ai_assisted;
            $this->aiNote = $model->ai_note;
            foreach ($model->targets as $target) {
                if (! $target->account) {
                    continue;
                }
                $ulid = $target->account->ulid;
                $this->selected[] = $ulid;
                $this->customOn[$ulid] = filled($target->body);
                $this->custom[$ulid] = (string) $target->body;
                $this->gbp[$ulid] = array_map('strval', (array) ($target->options ?? []));
            }
            if ($model->scheduled_at) {
                $local = $model->scheduled_at->setTimezone($timezone);
                $this->date = $local->format('Y-m-d');
                $this->time = $local->format('H:i');
            }
        } else {
            $this->authorize('social.manage', $organization);
            $this->selected = SocialAccount::query()->forOrganization($organization)->usable()->pluck('ulid')->all();
            $start = CarbonImmutable::now($timezone)->addHour()->startOfHour();
            $day = request()->query('date');
            $this->date = is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && $day >= $start->format('Y-m-d') ? $day : $start->format('Y-m-d');
            $this->time = $start->format('H:i');

            // From "Post about these openings" (D69): the free times and where to book.
            if (request()->query('openings') && ($days = app(Openings::class)->upcoming($organization)) !== []) {
                $links = app(LinkPage::class);
                $website = BusinessProfile::query()->forOrganization($organization)->value('website');
                $this->body = app(Openings::class)->postText($organization, $days,
                    $links->isLive($organization) ? $links->url($organization).'#surehelp-book' : ($website ?: null));
                $this->aiBrief = 'Last-minute openings: '.implode('; ', array_map(fn ($d) => $d['date']->format('l').' '.implode(', ', array_map(fn ($t) => $t->format('g:i A'), $d['times'])), $days)).'. Invite people to book.';
                $this->date = $start->format('Y-m-d');
                $this->time = $start->format('H:i');
            }
        }
    }

    public function save(string $intent, SavePost $save): mixed
    {
        $organization = $this->organization();
        $this->authorize('social.manage', $organization);
        abort_unless(in_array($intent, SavePost::INTENTS, true), 400);

        $this->validate([
            'body' => ['nullable', 'string', 'max:63206'],
            'link' => ['nullable', 'url:http,https', 'max:2000'],
            'date' => [$intent === 'schedule' ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'time' => [$intent === 'schedule' ? 'required' : 'nullable', 'date_format:H:i'],
            'custom.*' => ['nullable', 'string', 'max:63206'],
            'gbp.*.button_url' => ['nullable', 'url:http,https', 'max:2000'],
            'gbp.*.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'gbp.*.ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:gbp.*.starts_on'],
        ], ['link.url' => 'Enter a full web address, starting with https://']);

        $when = null;
        if ($this->date !== '' && $this->time !== '') {
            $when = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->date.' '.$this->time, $organization->timezoneOrDefault())?->utc();
        }

        try {
            $post = $save->handle($organization, $this->post(), [
                'body' => $this->body,
                'link_url' => $this->link,
                'accounts' => array_values($this->selected),
                'custom' => collect($this->selected)->mapWithKeys(fn ($u) => [$u => ($this->customOn[$u] ?? false) ? ($this->custom[$u] ?? '') : null])->all(),
                'options' => collect($this->selected)->mapWithKeys(fn ($u) => [$u => $this->gbp[$u] ?? []])->all(),
                'media' => array_values($this->media),
                'scheduled_at' => $intent === 'now' ? null : $when,   // a draft keeps its planned time for the calendar
                'ai_assisted' => $this->aiAssisted,
            ], auth()->user(), $intent);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field === 'scheduled_at' ? 'date' : $field, $message);
                }
            }

            return null;
        }

        $message = match ($post->status) {
            SocialPostStatus::Draft => 'Draft saved.',
            SocialPostStatus::InReview => 'Sent for approval. The owner has been notified.',
            default => $intent === 'now' ? 'Publishing now. This page shows the result in a minute.' : 'Scheduled.',
        };
        session()->flash('success', $message);

        return $this->redirectRoute('app.social.posts.edit', $post, navigate: false);
    }

    /** Write the post for each chosen network from a short brief (D66). */
    public function writeWithAi(ContentStudio $studio): void
    {
        $organization = $this->organization();
        $this->authorize('social.manage', $organization);
        $this->validate(['aiBrief' => ['required', 'string', 'min:5', 'max:1000'], 'aiTone' => ['required', 'in:'.implode(',', array_keys(ContentStudio::TONES))]],
            ['aiBrief.required' => 'Say what the post is about.', 'aiBrief.min' => 'Say a little more about what the post is about.']);

        $accounts = $this->selectedAccounts();
        try {
            $result = $studio->write($organization, auth()->user(), $this->aiBrief, $this->networksOf($accounts), $this->aiTone);
        } catch (ValidationException $e) {
            $this->addError('aiBrief', collect($e->errors())->flatten()->first());

            return;
        }

        $versions = $result['versions'];
        $this->body = $versions[$accounts->first()?->network->value] ?? reset($versions);
        foreach ($accounts as $account) {
            $text = $versions[$account->network->value] ?? $this->body;
            $this->customOn[$account->ulid] = $text !== $this->body;
            $this->custom[$account->ulid] = $text !== $this->body ? $text : '';
        }
        $this->aiFlags = $result['flags'];
        $this->aiAssisted = true;
    }

    /** Change the shared text one way: shorter, friendlier, more professional, with a call to action. */
    public function rewriteWithAi(string $how, ContentStudio $studio): void
    {
        $organization = $this->organization();
        $this->authorize('social.manage', $organization);
        abort_unless(array_key_exists($how, ContentStudio::REWRITES), 400);
        if (trim($this->body) === '') {
            $this->addError('aiBrief', 'Write or generate a post first.');

            return;
        }

        try {
            $result = $studio->rewrite($organization, auth()->user(), $this->body, $how, $this->networksOf($this->selectedAccounts()));
        } catch (ValidationException $e) {
            $this->addError('aiBrief', collect($e->errors())->flatten()->first());

            return;
        }

        $this->body = $result['text'];
        $this->aiFlags = $result['flags'];
        $this->aiAssisted = true;
    }

    /** @return Collection<int, SocialAccount> */
    private function selectedAccounts(): Collection
    {
        $order = array_flip(array_values($this->selected));

        return SocialAccount::query()->forOrganization($this->organization())->whereIn('ulid', $this->selected)->get()
            ->sortBy(fn (SocialAccount $a) => $order[$a->ulid] ?? 0)->values();
    }

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @return list<SocialNetwork>
     */
    private function networksOf(Collection $accounts): array
    {
        return array_values($accounts->map(fn (SocialAccount $a) => $a->network)->unique(fn (SocialNetwork $n) => $n->value)->all());
    }

    public function approve(PostWorkflow $workflow): void
    {
        $workflow->approve($this->requirePost(), auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Approved. It will be published as planned.');
    }

    public function requestChanges(PostWorkflow $workflow): void
    {
        $this->validate(['changesNote' => ['required', 'string', 'max:1000']], ['changesNote.required' => 'Say what should change.']);
        $workflow->requestChanges($this->requirePost(), auth()->user(), $this->changesNote);
        $this->changesNote = '';
        $this->dispatch('toast', type: 'success', message: 'Sent back with your note.');
    }

    public function cancel(PostWorkflow $workflow): void
    {
        $this->authorize('social.manage', $this->organization());
        $workflow->cancel($this->requirePost(), auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Post cancelled. Nothing will be published.');
    }

    public function retry(PostWorkflow $workflow): void
    {
        $this->authorize('social.manage', $this->organization());
        $workflow->retry($this->requirePost(), auth()->user());
        $this->dispatch('toast', type: 'success', message: 'Trying again now.');
    }

    public function deletePost(PostWorkflow $workflow): mixed
    {
        $this->authorize('social.manage', $this->organization());
        $workflow->delete($this->requirePost(), auth()->user());
        session()->flash('success', 'Post deleted.');

        return $this->redirectRoute('app.social.index', navigate: false);
    }

    public function updatedUploads(StoreMedia $store): void
    {
        $organization = $this->organization();
        $this->authorize('social.manage', $organization);
        $this->validate(...Media::uploadRules('uploads'));

        foreach ($this->uploads as $file) {
            if (count($this->media) < 10) {
                $this->media[] = $store->handle($organization, $file, auth()->user())->ulid;
            }
        }
        $this->reset('uploads');
    }

    public function addMedia(string $ulid): void
    {
        if (! in_array($ulid, $this->media, true) && count($this->media) < 10
            && MediaAsset::query()->forOrganization($this->organization())->where('ulid', $ulid)->exists()) {
            $this->media[] = $ulid;
        }
    }

    public function removeMedia(string $ulid): void
    {
        $this->media = array_values(array_diff($this->media, [$ulid]));
    }

    public function moveMedia(string $ulid, int $direction): void
    {
        $i = array_search($ulid, $this->media, true);
        $j = $i === false ? false : $i + ($direction < 0 ? -1 : 1);
        if ($i !== false && isset($this->media[$j])) {
            [$this->media[$i], $this->media[$j]] = [$this->media[$j], $this->media[$i]];
        }
    }

    private function post(): ?SocialPost
    {
        return $this->postUlid
            ? SocialPost::query()->forOrganization($this->organization())->where('ulid', $this->postUlid)->firstOrFail()
            : null;
    }

    private function requirePost(): SocialPost
    {
        return $this->post() ?? abort(404);
    }

    public function render(PostValidator $validator, SocialApproval $approval, ContentStudio $studio, ContentTokens $tokens): View
    {
        $organization = $this->organization();
        $post = $this->post()?->load(['targets.account', 'author', 'approver']);
        $accounts = SocialAccount::query()->forOrganization($organization)->where('is_enabled', true)->orderBy('network')->orderBy('name')->get();
        /** @var Collection<string, MediaAsset> $mediaAssets */
        $mediaAssets = MediaAsset::query()->forOrganization($organization)->whereIn('ulid', $this->media)->get()->keyBy('ulid');
        $link = $this->link !== '' ? $this->link : null;

        $checks = $accounts->whereIn('ulid', $this->selected)->mapWithKeys(fn (SocialAccount $a) => [
            $a->ulid => $validator->check($a->network, $this->textFor($a->ulid), $link, count($this->media)),
        ]);

        $editable = ! $post || $post->status->isEditable();
        $canManage = auth()->user()->can('social.manage', $organization);

        return view('livewire.client.social.compose', [
            'post' => $post,
            'accounts' => $accounts,
            'checks' => $checks,
            'mediaAssets' => $mediaAssets,
            'library' => $this->pickingMedia ? MediaAsset::query()->forOrganization($organization)->latest('id')->limit(48)->get() : collect(),
            'editable' => $editable && $canManage,
            'canManage' => $canManage,
            'canApprove' => $post && $post->status === SocialPostStatus::InReview && $approval->canApprove($organization, auth()->user()),
            'needsApproval' => $approval->needsApproval($organization, auth()->user(), $post->source ?? 'manual'),
            'timezone' => $organization->timezoneOrDefault(),
            'buttons' => GoogleBusinessPublisher::BUTTONS,
            'topics' => GoogleBusinessPublisher::TOPICS,
            'google' => SocialNetwork::GoogleBusiness,
            'aiBlocker' => $studio->blocker($organization),
            'aiUsedPercent' => $tokens->percentUsed($organization),
            'tones' => ContentStudio::TONES,
            'rewrites' => ContentStudio::REWRITES,
        ])->title($post ? 'Social post' : 'New social post');
    }

    public function textFor(string $ulid): string
    {
        return ($this->customOn[$ulid] ?? false) && filled($this->custom[$ulid] ?? null) ? (string) $this->custom[$ulid] : $this->body;
    }
}
