<?php

namespace App\Services\Social\House;

use App\Actions\Social\SavePost;
use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Exceptions\AiUnavailable;
use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Notifications\SocialApprovalRequested;
use App\Services\Ai\AiImages;
use App\Services\Ai\AiProviders;
use App\Services\Ai\Data\AiRequest;
use App\Services\Social\PostValidator;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Fills SureHelp's posting schedule (D104). Every 15 minutes it prepares the posts due in the next 24
 * hours, once per time slot: it picks the kind of post (text, picture or video, by the chosen mix and
 * what the library has), the media (least recently used first, or a new ChatGPT picture), and asks the
 * chosen AI for a version per network. Posts are scheduled for their slot, or wait for approval when
 * that switch is on. Anything can be edited or cancelled before it goes out.
 */
class HouseAutopilot
{
    public const LEAD_HOURS = 24;

    public function __construct(
        private readonly HouseSocial $house,
        private readonly SavePost $save,
        private readonly PostValidator $validator,
        private readonly AiProviders $providers,
        private readonly AiImages $images,
        private readonly Audit $audit,
    ) {}

    /**
     * Prepares every slot due in the next 24 hours that has no post yet.
     *
     * @return array{prepared: int, errors: list<string>}
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $settings = $this->house->settings();
        if (! $settings['enabled']) {
            return ['prepared' => 0, 'errors' => []];
        }

        $organization = $this->house->organization();
        $prepared = 0;
        $errors = [];
        foreach ($this->dueSlots($now ?? CarbonImmutable::now()) as $slot) {
            if (SocialPost::query()->forOrganization($organization)->where('autopilot_slot', self::key($slot))->exists()) {
                continue;
            }
            try {
                $this->prepare($slot);
                $prepared++;
            } catch (Throwable $e) {
                if (! $e instanceof RuntimeException && ! $e instanceof ValidationException) {   // AiUnavailable is a RuntimeException
                    report($e);
                }
                $errors[] = $slot->format('D j M, g:i A').': '.($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage());
            }
        }

        PlatformSetting::put(HouseSocial::LAST_RUN, ['at' => now()->toIso8601String(), 'prepared' => $prepared, 'errors' => array_slice($errors, 0, 5)]);

        return ['prepared' => $prepared, 'errors' => $errors];
    }

    /**
     * Upcoming slots in SureHelp's time zone, from 5 minutes from now to 24 hours ahead.
     *
     * @return list<CarbonImmutable>
     */
    public function dueSlots(CarbonImmutable $now, int $hours = self::LEAD_HOURS): array
    {
        $settings = $this->house->settings();
        $local = $now->setTimezone($settings['timezone']);
        $slots = [];
        for ($day = 0; $day <= (int) ceil($hours / 24); $day++) {
            $date = $local->startOfDay()->addDays($day);
            if (! in_array($date->dayOfWeekIso, $settings['days'], true)) {
                continue;
            }
            foreach ($settings['times'] as $time) {
                [$h, $m] = array_map('intval', explode(':', $time));
                $slot = $date->setTime($h, $m);
                if ($slot->greaterThan($local->addMinutes(5)) && $slot->lessThanOrEqualTo($local->addHours($hours))) {
                    $slots[] = $slot;
                }
            }
        }

        return $slots;
    }

    /**
     * Prepares the post for one slot (also used by "Prepare the next post now").
     *
     * @throws RuntimeException|ValidationException|AiUnavailable when nothing can be posted
     */
    public function prepare(CarbonImmutable $slot): SocialPost
    {
        $settings = $this->house->settings();
        $organization = $this->house->organization();
        $author = $this->house->author() ?? throw new RuntimeException('No active admin to post as.');
        $accounts = SocialAccount::query()->forOrganization($organization)->usable()
            ->when($settings['accounts'] !== [], fn ($q) => $q->whereIn('ulid', $settings['accounts']))->get();
        if ($accounts->isEmpty()) {
            throw new RuntimeException('Connect at least one account under Admin › Social › Accounts.');
        }

        [$kind, $media] = $this->pickKindAndMedia($organization, $slot, $settings, $accounts);
        $targets = $this->accountsFor($kind, $media, $accounts);
        $topic = $this->topic($settings, $organization);

        // Ask the AI for one version per network.
        $networks = $targets->pluck('network')->unique(fn ($n) => $n->value)->values()->all();
        $written = $this->write($settings, $topic, $kind, $media, $networks, $slot, $organization);

        if ($kind === 'image' && ! $media) {
            $media = $this->images->generate($organization, $written['picture_prompt'] ?: 'A friendly, bright photo-style image about: '.$topic, alt: $written['alt_text'] ?: null);
        }

        // Each account gets its network's text; one that still breaks its network's rules is left out.
        $custom = [];
        $kept = $targets->filter(function (SocialAccount $account) use ($written, $media, &$custom) {
            $text = $written['posts'][$account->network->value] ?? $written['posts']['default'] ?? '';
            $videos = $media?->isVideo() ? [$media->duration_seconds] : [];
            $check = $this->validator->check($account->network, $text, null, $media && ! $media->isVideo() ? 1 : 0, $videos);
            if ($check['errors'] !== []) {
                return false;
            }
            $custom[$account->ulid] = $text;

            return true;
        });
        if ($kept->isEmpty()) {
            throw new RuntimeException('The AI\'s text didn\'t fit any network\'s rules. Try again, or adjust the topics.');
        }

        $post = $this->save->handle($organization, null, [
            'body' => (string) reset($custom),
            'accounts' => $kept->pluck('ulid')->values()->all(),
            'custom' => $custom,
            'media' => $media ? [$media->ulid] : [],
            'scheduled_at' => $slot->utc(),
            'ai_assisted' => true,
        ], $author, 'schedule', 'autopilot', self::key($slot));

        $media?->forceFill(['last_used_at' => now()])->save();
        $this->audit->record('house_social.prepared', $post, new: ['kind' => $kind, 'slot' => $slot->toIso8601String(), 'topic' => $topic,
            'ai' => $this->providers->label(config('ai.social_provider') ?: null)], organization: $organization, label: $post->excerpt(60));
        if ($post->status === SocialPostStatus::InReview) {
            $author->notify(new SocialApprovalRequested($post, 'SureHelp\'s posting schedule'));
        }

        return $post;
    }

    /** The next slot without a post, for "Prepare the next post now". */
    public function nextFreeSlot(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $organization = $this->house->organization();
        foreach ($this->dueSlots($now ?? CarbonImmutable::now(), 24 * 8) as $slot) {
            if (! SocialPost::query()->forOrganization($organization)->where('autopilot_slot', self::key($slot))->exists()) {
                return $slot;
            }
        }

        return null;
    }

    public static function key(CarbonImmutable $slot): string
    {
        return $slot->utc()->format('Y-m-d H:i');
    }

    /**
     * Text, picture or video: by the mix (steady per slot), falling back to what the library and the
     * connected networks allow.
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @return array{0: string, 1: ?MediaAsset}
     */
    private function pickKindAndMedia(Organization $organization, CarbonImmutable $slot, array $settings, Collection $accounts): array
    {
        $mix = array_filter($settings['mix']);
        $roll = crc32(self::key($slot)) % max(1, array_sum($mix));
        $order = [];
        foreach ($mix as $kind => $weight) {
            if ($roll < $weight && $order === []) {
                $order[] = $kind;
            }
            $roll -= $weight;
        }
        $order = array_values(array_unique([...$order, ...array_keys($mix), 'image', 'text']));

        foreach ($order as $kind) {
            if ($kind === 'video') {
                $video = $this->leastUsed($organization, true);
                if ($video && $this->accountsFor('video', $video, $accounts)->isNotEmpty()) {
                    return ['video', $video];
                }
            } elseif ($kind === 'image') {
                $picture = $settings['pictures'] === 'always' ? null : $this->leastUsed($organization, false);
                $stale = $picture && $settings['pictures'] === 'when_needed' && $picture->last_used_at?->greaterThan(now()->subDays(30));
                if ($picture && (! $stale || ! $this->images->available())) {
                    return ['image', $picture];
                }
                if ($settings['pictures'] !== 'library' && $this->images->available()) {
                    return ['image', null];   // ChatGPT makes it after the text is written
                }
            } elseif ($this->accountsFor('text', null, $accounts)->isNotEmpty()) {
                return ['text', null];
            }
        }

        throw new RuntimeException('Nothing to post: add pictures or videos to the library, connect ChatGPT for pictures, or connect a network that takes text posts.');
    }

    private function leastUsed(Organization $organization, bool $video): ?MediaAsset
    {
        return MediaAsset::query()->forOrganization($organization)
            ->where('mime', $video ? 'like' : 'not like', 'video/%')
            ->orderByRaw('last_used_at IS NOT NULL')->orderBy('last_used_at')->orderBy('id')->first();
    }

    /**
     * Accounts whose network takes this kind of post.
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @return Collection<int, SocialAccount>
     */
    private function accountsFor(string $kind, ?MediaAsset $media, Collection $accounts): Collection
    {
        return $accounts->filter(function (SocialAccount $a) use ($kind, $media) {
            $caps = $a->network->capabilities();

            return match ($kind) {
                'video' => $caps['videos'] > 0 && ($caps['max_video_seconds'] === null || (int) $media?->duration_seconds <= $caps['max_video_seconds']),
                'image' => $caps['max_images'] > 0 && ! $caps['requires_video'],
                default => ! $caps['requires_media'],
            };
        })->values();
    }

    /** Topics take turns, so consecutive posts cover different ground. */
    private function topic(array $settings, Organization $organization): string
    {
        $topics = array_values(array_filter(array_map('trim', preg_split('/\R/', $settings['topics']) ?: [])));
        if ($topics === []) {
            return 'Why every call matters to a small business';
        }
        $count = SocialPost::query()->forOrganization($organization)->whereNotNull('autopilot_slot')->count();

        return $topics[$count % count($topics)];
    }

    /**
     * @param  list<SocialNetwork>  $networks
     * @return array{posts: array<string, string>, picture_prompt: string, alt_text: string}
     */
    private function write(array $settings, string $topic, string $kind, ?MediaAsset $media, array $networks, CarbonImmutable $slot, Organization $organization): array
    {
        $recent = SocialPost::query()->forOrganization($organization)->latest('id')->limit(8)->pluck('body')
            ->map(fn ($b) => '- '.mb_substr(str_replace("\n", ' ', (string) $b), 0, 160))->implode("\n");
        $rules = collect($networks)->map(fn (SocialNetwork $n) => '- '.$n->value.': at most '.$n->capabilities()['max_chars'].' characters'
            .($n === SocialNetwork::YouTube ? '; the first line is the video title (under 100 characters)' : '')
            .($n === SocialNetwork::Instagram ? '; no links (they aren\'t clickable), 3-8 hashtags at the end' : '')
            .($n === SocialNetwork::X ? '; including any link and hashtags' : '')
            .($n === SocialNetwork::GoogleBusiness ? '; no hashtags and no phone numbers' : ''))->implode("\n");
        $about = match ($kind) {
            'video' => 'The post has a video: "'.($media->alt_text ?: pathinfo((string) $media->original_name, PATHINFO_FILENAME)).'"'.($media->duration_seconds ? ' ('.$media->duration_seconds.' seconds)' : '').'. Write about what it shows.',
            'image' => $media ? 'The post has a picture: "'.($media->alt_text ?: pathinfo((string) $media->original_name, PATHINFO_FILENAME)).'". Write to go with it.' : 'The post will have a picture made from your picture_prompt: describe a simple, realistic scene that fits the text (no words in the picture).',
            default => 'The post is text only.',
        };

        $instructions = "You write social media posts for SureHelp's own accounts.\n\nAbout SureHelp:\n{$settings['about']}\n\nVoice: {$settings['voice']}\n"
            .(filled($settings['link']) ? "Website: {$settings['link']} (include it where links are allowed, at most once).\n" : '')
            ."Write in US English. Only state facts given here; never invent numbers, prices, customers or quotes.\n\n"
            ."Write one version for each network, following its rules:\n{$rules}\n\nReturn JSON only.";
        $context = 'Today is '.$slot->format('l, F j, Y').'; this post goes out at '.$slot->format('g:i A T').".\nTopic: {$topic}\n{$about}\n"
            .($recent !== '' ? "Recent posts (don't repeat them):\n{$recent}" : '');

        $schema = [
            'type' => 'object',
            'properties' => [
                'posts' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'network' => ['type' => 'string', 'enum' => array_map(fn (SocialNetwork $n) => $n->value, $networks)],
                    'text' => ['type' => 'string'],
                ], 'required' => ['network', 'text'], 'additionalProperties' => false]],
                'picture_prompt' => ['type' => 'string'],
                'alt_text' => ['type' => 'string'],
            ],
            'required' => ['posts', 'picture_prompt', 'alt_text'],
            'additionalProperties' => false,
        ];

        $reply = $this->providers->forSocial()->complete(new AiRequest($instructions, $context,
            [['role' => 'user', 'content' => 'Write the post.']], outputSchema: $schema, effort: 'medium', maxTokens: 8000));
        $json = json_decode($reply->text, true);
        if (! is_array($json) || empty($json['posts'])) {
            throw new AiUnavailable('The AI didn\'t return a post. Try again.');
        }

        return [
            'posts' => collect($json['posts'])->filter(fn ($p) => is_array($p) && filled($p['text'] ?? null))
                ->mapWithKeys(fn ($p) => [(string) $p['network'] => trim((string) $p['text'])])->all(),
            'picture_prompt' => trim((string) ($json['picture_prompt'] ?? '')),
            'alt_text' => trim((string) ($json['alt_text'] ?? '')),
        ];
    }
}
