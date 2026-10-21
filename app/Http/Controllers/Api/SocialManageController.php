<?php

namespace App\Http\Controllers\Api;

use App\Actions\Social\PostWorkflow;
use App\Actions\Social\SavePost;
use App\Actions\Social\StoreMedia;
use App\Enums\SocialNetwork;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialPostResource;
use App\Http\Responses\ApiResponse;
use App\Livewire\Client\Social\Media;
use App\Models\BusinessProfile;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Growth\LinkPage;
use App\Services\Growth\Openings;
use App\Services\Social\Ai\ContentStudio;
use App\Services\Social\PostValidator;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Writing and scheduling social posts from the app (API-9): the same rules as the web composer (SavePost:
 * per-network checks, owner approval, scheduling window), photos, Write with AI and rewrites (D66),
 * last-minute openings and the link-in-bio page (D69).
 */
class SocialManageController extends Controller
{
    private const RELATIONS = ['targets.account', 'media', 'author:id,name'];

    public function accounts(CurrentOrganization $current): JsonResponse
    {
        $accounts = SocialAccount::query()->forOrganization($current->get())->orderBy('network')->orderBy('name')->get();

        return ApiResponse::success(['accounts' => $accounts->map(fn (SocialAccount $a) => [
            'id' => $a->ulid,
            'network' => $a->network->value,
            'network_label' => $a->network->label(),
            'name' => $a->displayName(),
            'avatar_url' => $a->avatar_url,
            'enabled' => $a->is_enabled,
            'needs_reconnect' => $a->needsReconnect(),
            'limits' => $a->network->capabilities(),
        ])->values()]);
    }

    public function show(Request $request, CurrentOrganization $current, string $ulid): JsonResponse
    {
        return ApiResponse::success(['post' => $this->present($request, $this->find($current->get(), $ulid))]);
    }

    /** Check a post for each chosen account without saving it, for live hints while writing. */
    public function check(Request $request, CurrentOrganization $current, PostValidator $validator): JsonResponse
    {
        $data = $request->validate($this->contentRules());
        $accounts = $this->accountsFor($current->get(), $data['accounts'] ?? []);

        return ApiResponse::success(['checks' => $accounts->map(fn (SocialAccount $a) => ['account' => $a->ulid]
            + $validator->check($a->network, $this->textFor($a, $data), $data['link_url'] ?? null, count($data['media'] ?? [])))->values()]);
    }

    public function store(Request $request, CurrentOrganization $current, SavePost $save): JsonResponse
    {
        return $this->save($request, $current->get(), null, $save, 201);
    }

    public function update(Request $request, CurrentOrganization $current, SavePost $save, string $ulid): JsonResponse
    {
        $organization = $current->get();

        return $this->save($request, $organization, $this->find($organization, $ulid), $save, 200);
    }

    public function cancel(Request $request, CurrentOrganization $current, PostWorkflow $workflow, string $ulid): JsonResponse
    {
        $post = $workflow->cancel($this->find($current->get(), $ulid), $request->user());

        return ApiResponse::success(['post' => $this->present($request, $post)], 'Post cancelled. Nothing will be published.');
    }

    public function retry(Request $request, CurrentOrganization $current, PostWorkflow $workflow, string $ulid): JsonResponse
    {
        $post = $workflow->retry($this->find($current->get(), $ulid), $request->user());

        return ApiResponse::success(['post' => $this->present($request, $post)], 'Trying again now.');
    }

    public function destroy(Request $request, CurrentOrganization $current, PostWorkflow $workflow, string $ulid): JsonResponse
    {
        $workflow->delete($this->find($current->get(), $ulid), $request->user());

        return ApiResponse::success(null, 'Post deleted.');
    }

    /** A photo for posts (multipart `photo`, optional `alt`) → its id for `media[]`. */
    public function upload(Request $request, CurrentOrganization $current, StoreMedia $store): JsonResponse
    {
        [$rules, $messages] = Media::uploadRules('photos');
        $request->validate(['photo' => $rules['photos.*'], 'alt' => ['nullable', 'string', 'max:250']],
            collect($messages)->mapWithKeys(fn ($m, $k) => [str_replace('photos.*', 'photo', $k) => $m])->all());
        $asset = $store->handle($current->get(), $request->file('photo'), $request->user(), $request->input('alt'));

        return ApiResponse::success(['media' => ['id' => $asset->ulid, 'url' => $asset->publicUrl(), 'alt' => $asset->alt_text]], 'Photo added.', 201);
    }

    public function write(Request $request, CurrentOrganization $current, ContentStudio $studio): JsonResponse
    {
        $organization = $current->get();
        $data = $request->validate([
            'brief' => ['required', 'string', 'min:5', 'max:1000'],
            'tone' => ['nullable', Rule::in(array_keys(ContentStudio::TONES))],
            'accounts' => ['nullable', 'array'], 'accounts.*' => ['string'],
        ]);
        $result = $studio->write($organization, $request->user(), $data['brief'], $this->networks($organization, $data['accounts'] ?? []), $data['tone'] ?? 'friendly');

        return ApiResponse::success($result + ['tones' => ContentStudio::TONES]);
    }

    public function rewrite(Request $request, CurrentOrganization $current, ContentStudio $studio): JsonResponse
    {
        $organization = $current->get();
        $data = $request->validate([
            'text' => ['required', 'string', 'max:63206'],
            'how' => ['required', Rule::in(array_keys(ContentStudio::REWRITES))],
            'accounts' => ['nullable', 'array'], 'accounts.*' => ['string'],
        ]);

        return ApiResponse::success($studio->rewrite($organization, $request->user(), $data['text'], $data['how'], $this->networks($organization, $data['accounts'] ?? [])));
    }

    /** Free times in the next days and a ready post about them (D69). */
    public function openings(CurrentOrganization $current, Openings $openings, LinkPage $page): JsonResponse
    {
        $organization = $current->get();
        $days = $openings->upcoming($organization);
        $website = BusinessProfile::query()->forOrganization($organization)->value('website');
        $link = $page->isLive($organization) ? $page->url($organization).'#surehelp-book' : ($website ?: null);

        return ApiResponse::success([
            'days' => array_map(fn (array $d) => ['date' => $d['date']->toDateString(), 'times' => array_map(fn (CarbonImmutable $t) => $t->toIso8601String(), $d['times'])], $days),
            'text' => $days === [] ? null : $openings->postText($organization, $days, $link),
        ]);
    }

    public function linkPage(CurrentOrganization $current, LinkPage $page): JsonResponse
    {
        $organization = $current->get();
        $profile = BusinessProfile::query()->forOrganization($organization)->first();

        return ApiResponse::success([
            'enabled' => (bool) $profile?->link_page_enabled,
            'live' => $page->isLive($organization),
            'url' => $page->url($organization),
            'links' => array_values((array) $profile?->link_page_links),
            'max_links' => LinkPage::MAX_LINKS,
        ]);
    }

    public function updateLinkPage(Request $request, CurrentOrganization $current, LinkPage $page, Audit $audit): JsonResponse
    {
        $organization = $current->get();
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'links' => ['nullable', 'array', 'max:'.LinkPage::MAX_LINKS],
            'links.*.label' => ['required', 'string', 'max:60'],
            'links.*.url' => ['required', 'url:http,https', 'max:500'],
        ]);
        $profile = BusinessProfile::firstOrNew(['organization_id' => $organization->id]);
        $profile->link_page_enabled = $data['enabled'];
        $profile->link_page_links = array_values(array_map(fn ($l) => ['label' => trim($l['label']), 'url' => trim($l['url'])], $data['links'] ?? [])) ?: null;
        $profile->save();
        $audit->changes('social.link_page_changed', $profile, ['link_page_enabled', 'link_page_links']);

        return $this->linkPage($current, $page);
    }

    private function save(Request $request, Organization $organization, ?SocialPost $post, SavePost $save, int $status): JsonResponse
    {
        $data = $request->validate($this->contentRules() + [
            'intent' => ['required', Rule::in(SavePost::INTENTS)],
            'scheduled_at' => ['nullable', 'date'],
            'options' => ['nullable', 'array'],
            'ai_assisted' => ['nullable', 'boolean'],
        ]);
        $when = filled($data['scheduled_at'] ?? null) ? CarbonImmutable::parse($data['scheduled_at'])->utc() : null;

        try {
            $post = $save->handle($organization, $post, [
                'body' => (string) ($data['body'] ?? ''),
                'link_url' => $data['link_url'] ?? null,
                'accounts' => array_values($data['accounts'] ?? []),
                'custom' => (array) ($data['custom'] ?? []),
                'options' => (array) ($data['options'] ?? []),
                'media' => array_values($data['media'] ?? []),
                'scheduled_at' => $data['intent'] === 'now' ? null : $when,
                'ai_assisted' => (bool) ($data['ai_assisted'] ?? false),
            ], $request->user(), $data['intent']);
        } catch (ValidationException $e) {
            return ApiResponse::error(collect($e->errors())->flatten()->first() ?? 'Please check the post.', 422, $e->errors());
        }

        $message = match ($post->status->value) {
            'draft' => 'Draft saved.',
            'in_review' => 'Sent for approval. The owner has been notified.',
            default => $data['intent'] === 'now' ? 'Publishing now.' : 'Scheduled.',
        };

        return ApiResponse::success(['post' => $this->present($request, $post)], $message, $status);
    }

    /** @return array<string, list<mixed>> */
    private function contentRules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:63206'],
            'link_url' => ['nullable', 'url:http,https', 'max:2000'],
            'accounts' => ['nullable', 'array'], 'accounts.*' => ['string'],
            'custom' => ['nullable', 'array'], 'custom.*' => ['nullable', 'string', 'max:63206'],
            'media' => ['nullable', 'array', 'max:10'], 'media.*' => ['string'],
        ];
    }

    /**
     * @param  array<int, string>  $ulids
     * @return Collection<int, SocialAccount>
     */
    private function accountsFor(Organization $organization, array $ulids): Collection
    {
        return SocialAccount::query()->forOrganization($organization)->whereIn('ulid', $ulids)->get();
    }

    /**
     * @param  array<int, string>  $ulids
     * @return list<SocialNetwork>
     */
    private function networks(Organization $organization, array $ulids): array
    {
        $accounts = $ulids === [] ? SocialAccount::query()->forOrganization($organization)->usable()->get() : $this->accountsFor($organization, $ulids);

        return array_values($accounts->map(fn (SocialAccount $a) => $a->network)->unique(fn (SocialNetwork $n) => $n->value)->all());
    }

    /** @param array<string, mixed> $data */
    private function textFor(SocialAccount $account, array $data): string
    {
        $custom = $data['custom'][$account->ulid] ?? null;

        return filled($custom) ? (string) $custom : (string) ($data['body'] ?? '');
    }

    private function find(Organization $organization, string $ulid): SocialPost
    {
        return SocialPost::query()->forOrganization($organization)->where('ulid', $ulid)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(Request $request, SocialPost $post): array
    {
        $post->load(self::RELATIONS);

        return (new SocialPostResource($post))->resolve($request) + [
            'ai_note' => $post->ai_note,
            'custom' => $post->targets->filter(fn ($t) => $t->account !== null && filled($t->body))->mapWithKeys(fn ($t) => [$t->account->ulid => $t->body])->all(),
            'account_ids' => $post->targets->filter(fn ($t) => $t->account !== null)->map(fn ($t) => $t->account->ulid)->values()->all(),
            // Additive (D76): per-account options (Google: topic, button, button_url, title, starts_on, ends_on, coupon, terms).
            'options' => (object) $post->targets->filter(fn ($t) => $t->account !== null && filled($t->options))->mapWithKeys(fn ($t) => [$t->account->ulid => $t->options])->all(),
            'media_ids' => $post->media->pluck('ulid')->all(),
            'editable' => $post->status->isEditable(),
        ];
    }
}
