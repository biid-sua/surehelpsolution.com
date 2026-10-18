<?php

namespace App\Services\Social\Ai;

use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Exceptions\AiUnavailable;
use App\Jobs\PlanSocialMonth;
use App\Models\BusinessProfile;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialAiRun;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Seo\SeoFacts;
use App\Services\Social\PostValidator;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The AI content studio for social posts (G-2, D66): write a post for each network from a short brief,
 * rewrite one, or draft a month of posts into the calendar. The AI may only state the business's public
 * details; anything it can't back up, and any number that isn't in those details, is flagged for a
 * person. Nothing it writes is published without a person scheduling it, and month-plan drafts need an
 * owner's approval.
 */
class ContentStudio
{
    public const TONES = [
        'friendly' => 'Friendly',
        'professional' => 'Professional',
        'playful' => 'Light and playful',
        'urgent' => 'Timely, with a clear call to action',
    ];

    public const REWRITES = [
        'shorter' => 'Shorter',
        'friendlier' => 'Friendlier',
        'professional' => 'More professional',
        'cta' => 'Add a call to action',
    ];

    public function __construct(
        private readonly AiProvider $ai,
        private readonly SeoFacts $facts,
        private readonly ContentTokens $tokens,
        private readonly FactCheck $factCheck,
        private readonly PostValidator $validator,
        private readonly Audit $audit,
    ) {}

    /** Why the studio can't be used right now, or null. */
    public function blocker(Organization $organization): ?string
    {
        return match (true) {
            ! $this->ai->isConfigured() => 'Writing with AI isn\'t switched on yet.',
            ! $this->tokens->canStart($organization) => 'You\'ve used this month\'s AI allowance for posts. It renews on the 1st.',
            default => null,
        };
    }

    /**
     * One post per network from the business's brief.
     *
     * @param  list<SocialNetwork>  $networks
     * @return array{versions: array<string, string>, flags: list<string>}
     *
     * @throws ValidationException
     */
    public function write(Organization $organization, User $by, string $brief, array $networks, string $tone): array
    {
        $networks = $networks ?: [SocialNetwork::Facebook];
        $tone = array_key_exists($tone, self::TONES) ? $tone : 'friendly';
        $prompt = 'Write one post for each of these networks: '.$this->names($networks).".\n\n"
            ."What the business wants to post about:\n".trim($brief)."\n\n"
            .'Tone: '.self::TONES[$tone].".\n\n".$this->networkRules($networks);

        $data = $this->ask($organization, $by, 'write', ['brief' => Str::limit($brief, 1000), 'networks' => $this->values($networks), 'tone' => $tone], $prompt, $this->object([
            'posts' => $this->list($this->object([
                'network' => ['type' => 'string', 'enum' => $this->values($networks)],
                'text' => ['type' => 'string'],
            ])),
            'unsupported_claims' => $this->list(['type' => 'string']),
        ]));

        $versions = [];
        foreach ((array) ($data['posts'] ?? []) as $post) {
            $network = SocialNetwork::tryFrom((string) ($post['network'] ?? ''));
            if ($network && in_array($network, $networks, true) && filled($post['text'] ?? null)) {
                $versions[$network->value] ??= $this->fit((string) $post['text'], $network);
            }
        }
        if ($versions === []) {
            throw ValidationException::withMessages(['ai' => ['The AI didn\'t return a post. Try rewording your idea.']]);
        }
        foreach ($networks as $network) {
            $versions[$network->value] ??= $this->fit((string) reset($versions), $network);
        }

        return ['versions' => $versions, 'flags' => $this->flags($organization, $versions, (array) ($data['unsupported_claims'] ?? []))];
    }

    /**
     * The same post, changed one way. The result must fit every network it goes to.
     *
     * @param  list<SocialNetwork>  $networks
     * @return array{text: string, flags: list<string>}
     *
     * @throws ValidationException
     */
    public function rewrite(Organization $organization, User $by, string $text, string $how, array $networks): array
    {
        abort_unless(array_key_exists($how, self::REWRITES), 400);
        $networks = $networks ?: [SocialNetwork::Facebook];
        $change = match ($how) {
            'shorter' => 'Make it about half as long. Keep the main point and any call to action.',
            'friendlier' => 'Make it warmer and more personal, as if the owner were talking to a neighbour.',
            'professional' => 'Make it more professional and polished, without sounding stiff.',
            default => 'End with one clear call to action that fits the business (call, book or visit), using only contact details from the facts.',
        };
        $prompt = "Rewrite this social media post. {$change} Keep every fact; don't add new ones.\n\n"
            .'The post goes to: '.$this->names($networks).". It must follow the rules for all of them.\n\n".$this->networkRules($networks)
            ."\n\nThe post:\n".trim($text);

        $data = $this->ask($organization, $by, 'rewrite', ['how' => $how, 'networks' => $this->values($networks)], $prompt, $this->object([
            'text' => ['type' => 'string'],
            'unsupported_claims' => $this->list(['type' => 'string']),
        ]));

        $result = trim((string) ($data['text'] ?? ''));
        if ($result === '') {
            throw ValidationException::withMessages(['ai' => ['The AI didn\'t return a new version. Try again.']]);
        }
        $result = $this->fit($result, $this->strictest($networks));

        return ['text' => $result, 'flags' => $this->flags($organization, array_fill_keys($this->values($networks), $result), (array) ($data['unsupported_claims'] ?? []))];
    }

    /**
     * Queue a month of draft posts. They land in the calendar as "AI draft" for a person to finish.
     *
     * @throws ValidationException
     */
    public function requestPlan(Organization $organization, User $by, string $month, int $perWeek, string $focus): SocialAiRun
    {
        if ($blocker = $this->blocker($organization)) {
            throw ValidationException::withMessages(['plan' => [$blocker]]);
        }
        $timezone = $organization->timezoneOrDefault();
        $start = preg_match('/^\d{4}-\d{2}$/', $month) ? CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', $timezone)?->startOfDay() : null;
        $thisMonth = CarbonImmutable::now($timezone)->startOfMonth();
        if (! $start || $start->lessThan($thisMonth) || $start->greaterThan($thisMonth->addMonthsNoOverflow(3))) {
            throw ValidationException::withMessages(['plan' => ['Choose this month or one of the next three.']]);
        }
        if (SocialAiRun::query()->forOrganization($organization)->where('kind', 'plan')->whereIn('status', ['queued', 'running'])->exists()) {
            throw ValidationException::withMessages(['plan' => ['A month plan is already being written. It takes a minute or two.']]);
        }

        $run = SocialAiRun::create([
            'organization_id' => $organization->id,
            'user_id' => $by->id,
            'kind' => 'plan',
            'status' => 'queued',
            'input' => ['month' => $start->format('Y-m'), 'per_week' => max(1, min((int) config('social.ai.max_posts_per_week', 5), $perWeek)), 'focus' => Str::limit(trim($focus), 1000)],
        ]);
        $this->audit->record('social.ai_plan_requested', $run, new: $run->input, organization: $organization, actor: $by, label: $start->format('F Y'));
        PlanSocialMonth::dispatch($run->id);

        return $run;
    }

    /** Writes the month plan and saves its posts as drafts (from the queue). */
    public function runPlan(SocialAiRun $run): void
    {
        $organization = Organization::findOrFail($run->organization_id);
        $timezone = $organization->timezoneOrDefault();
        $input = (array) $run->input;
        $start = CarbonImmutable::createFromFormat('Y-m-d', $input['month'].'-01', $timezone)->startOfDay();
        $first = CarbonImmutable::now($timezone)->addDay()->startOfDay()->max($start);
        $last = $start->endOfMonth()->startOfDay();
        if ($first->greaterThan($last)) {
            $run->forceFill(['status' => 'failed', 'error' => 'That month has no days left to plan.'])->save();

            return;
        }

        $accounts = SocialAccount::query()->forOrganization($organization)->usable()->get();
        $networks = array_values(array_unique($accounts->map(fn (SocialAccount $a) => $a->network)->all(), SORT_REGULAR)) ?: [SocialNetwork::Facebook];
        $count = min(25, (int) $input['per_week'] * (int) ceil(($first->diffInDays($last) + 1) / 7));
        $prompt = "Plan {$count} social media posts for {$start->format('F Y')}, between {$first->format('Y-m-d')} and {$last->format('Y-m-d')}, "
            .'spread evenly, each between 08:00 and 19:00. They go to: '.$this->names($networks).", so each text must follow the rules for all of them.\n\n"
            .$this->networkRules($networks)."\n\n"
            ."Mix useful tips, the business's services, local or seasonal topics for {$start->format('F')}, and the people behind the business. "
            ."Mention offers or prices only if the facts include them. Suggest a photo the business could take for each post.\n\n"
            .(filled($input['focus'] ?? null) ? "What the business wants to focus on this month:\n{$input['focus']}" : 'The business gave no particular focus this month.');

        $run->forceFill(['status' => 'running'])->save();
        try {
            $data = $this->ask($organization, null, 'plan', [], $prompt, $this->object([
                'posts' => $this->list($this->object([
                    'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'time' => ['type' => 'string', 'description' => 'HH:MM, 24-hour'],
                    'theme' => ['type' => 'string'],
                    'text' => ['type' => 'string'],
                    'photo_idea' => ['type' => 'string'],
                ])),
                'unsupported_claims' => $this->list(['type' => 'string']),
            ]), (string) config('social.ai.plan_effort', 'high'), $run);
        } catch (ValidationException $e) {
            return; // the run already says why
        }

        $facts = $this->factsFor($organization);
        $strictest = $this->strictest($networks);
        $created = 0;
        DB::transaction(function () use ($data, $organization, $run, $accounts, $first, $last, $timezone, $facts, $strictest, $count, &$created) {
            foreach (array_slice((array) ($data['posts'] ?? []), 0, $count) as $item) {
                $text = $this->fit(trim((string) ($item['text'] ?? '')), $strictest);
                $when = CarbonImmutable::createFromFormat('Y-m-d H:i', ($item['date'] ?? '').' '.($item['time'] ?? ''), $timezone);
                if ($text === '' || ! $when || $when->startOfDay()->lessThan($first) || $when->startOfDay()->greaterThan($last)) {
                    continue;
                }
                $flags = $this->factCheck->check($text, $facts);
                $note = implode(' · ', array_filter([
                    filled($item['theme'] ?? null) ? 'Idea: '.trim((string) $item['theme']) : null,
                    filled($item['photo_idea'] ?? null) ? 'Photo: '.trim((string) $item['photo_idea']) : null,
                    $flags ? 'Check: '.implode(' ', $flags) : null,
                ]));

                $post = SocialPost::create([
                    'organization_id' => $organization->id,
                    'body' => $text,
                    'status' => SocialPostStatus::Draft,
                    'scheduled_at' => $when->utc(),
                    'source' => 'ai',
                    'ai_assisted' => true,
                    'ai_note' => Str::limit($note, 497),
                    'created_by_user_id' => $run->user_id,
                ]);
                foreach ($accounts as $account) {
                    SocialPostTarget::create(['social_post_id' => $post->id, 'social_account_id' => $account->id, 'status' => SocialPostTarget::PENDING]);
                }
                $created++;
            }
        });

        $run->forceFill(['status' => 'done', 'output' => ['drafts' => $created, 'unsupported_claims' => array_values((array) ($data['unsupported_claims'] ?? []))]])->save();
        $this->audit->record('social.ai_plan_written', $run, new: ['drafts' => $created], organization: $organization, label: $start->format('F Y'));
    }

    /** The stable part of every request: the rules, the brand voice and the facts the AI may use. */
    public function instructions(Organization $organization): string
    {
        $voice = trim((string) BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->value('brand_voice'));

        return "You write social media posts for a local business, as the business (\"we\").\n\n"
            ."Rules:\n"
            ."- Use only the facts below. Never invent prices, discounts, awards, guarantees, reviews, years in business, staff names or contact details.\n"
            ."- If what the business asks for needs a fact that isn't below, write around it and list that claim in unsupported_claims.\n"
            ."- Write plain, natural English for customers, not marketing jargon. No made-up statistics.\n"
            ."- Never mention SureHelp or that the post was written by AI.\n"
            ."- Answer only with the JSON asked for.\n\n"
            .($voice !== '' ? "How the business wants to sound (follow this unless it conflicts with the rules):\n".Str::limit($voice, 1000)."\n\n" : '')
            .$this->factsFor($organization);
    }

    private function factsFor(Organization $organization): string
    {
        return $this->facts->for($organization);
    }

    /**
     * One structured request, metered against the month's allowance.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     *
     * @throws ValidationException with the reason, after recording the run
     */
    private function ask(Organization $organization, ?User $by, string $kind, array $input, string $prompt, array $schema, ?string $effort = null, ?SocialAiRun $run = null): array
    {
        if (! $run && ($blocker = $this->blocker($organization))) {
            throw ValidationException::withMessages(['ai' => [$blocker]]);
        }
        $run ??= new SocialAiRun(['organization_id' => $organization->id, 'user_id' => $by?->id, 'kind' => $kind, 'status' => 'running', 'input' => $input]);

        try {
            $response = $this->ai->complete(new AiRequest(
                $this->instructions($organization), '', [['role' => 'user', 'content' => $prompt]],
                outputSchema: $schema, effort: $effort ?? (string) config('social.ai.effort', 'medium'),
            ));
        } catch (AiUnavailable) {
            $run->forceFill(['status' => 'failed', 'error' => 'The AI service is unavailable. No allowance was used.'])->save();
            throw ValidationException::withMessages(['ai' => ['The AI service is unavailable right now. Try again in a few minutes.']]);
        }

        $run->forceFill([
            'model' => $this->ai->model(),
            'input_tokens' => $response->usage['input'] + $response->usage['cache_read'],
            'output_tokens' => $response->usage['output'],
        ]);
        $data = $response->stopReason === 'end_turn' ? json_decode($response->text, true) : null;
        if (! is_array($data)) {
            $why = match ($response->stopReason) {
                'refusal' => 'The AI declined to write this. Try a different idea.',
                'max_tokens' => 'The AI\'s answer was cut off. Try asking for less.',
                default => 'The AI\'s answer couldn\'t be read. Try again.',
            };
            $run->forceFill(['status' => 'failed', 'error' => $why])->save();
            throw ValidationException::withMessages(['ai' => [$why]]);
        }

        $run->forceFill(['status' => $kind === 'plan' ? 'running' : 'done', 'output' => $kind === 'plan' ? null : $data])->save();

        return $data;
    }

    /**
     * @param  array<string, string>  $versions  network => text
     * @param  array<int, mixed>  $claims
     * @return list<string>
     */
    private function flags(Organization $organization, array $versions, array $claims): array
    {
        $facts = $this->factsFor($organization);
        $flags = array_map(fn ($c) => 'The AI couldn\'t confirm: '.Str::limit(trim((string) $c), 200), array_filter($claims, fn ($c) => is_string($c) && trim($c) !== ''));
        foreach ($versions as $network => $text) {
            array_push($flags, ...$this->factCheck->check($text, $facts));
            foreach ($this->validator->check(SocialNetwork::from($network), $text, null, 1)['errors'] as $error) {
                $flags[] = SocialNetwork::from($network)->label().': '.$error;
            }
        }

        return array_values(array_unique($flags));
    }

    /** Cut a too-long text at the last sentence or word that fits. */
    private function fit(string $text, SocialNetwork $network): string
    {
        $max = (int) $network->capabilities()['max_chars'];
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $end = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, "\n"));

        return rtrim($end > $max / 2 ? mb_substr($cut, 0, $end + 1) : (string) preg_replace('/\s+\S*$/u', '', $cut));
    }

    /** @param list<SocialNetwork> $networks */
    private function strictest(array $networks): SocialNetwork
    {
        return collect($networks ?: [SocialNetwork::Facebook])->sortBy(fn (SocialNetwork $n) => $n->capabilities()['max_chars'])->first();
    }

    /** @param list<SocialNetwork> $networks */
    private function networkRules(array $networks): string
    {
        $rules = array_map(fn (SocialNetwork $n) => '- '.match ($n) {
            SocialNetwork::Facebook => 'Facebook: conversational, 40–120 words, up to 3 hashtags; a web link is fine.',
            SocialNetwork::Instagram => 'Instagram: a strong first line, short paragraphs, a few emojis at most, 3–8 relevant hashtags at the end, no web links (they aren\'t clickable), at most 2,200 characters.',
            SocialNetwork::LinkedIn => 'LinkedIn: helpful and professional, 50–150 words, up to 3 hashtags, at most 3,000 characters.',
            SocialNetwork::GoogleBusiness => 'Google Business Profile: plain and local, 50–150 words, no hashtags and no phone numbers (Google rejects posts with them), at most 1,500 characters.',
        }, $networks);

        return "Network rules:\n".implode("\n", $rules);
    }

    /** @param list<SocialNetwork> $networks */
    private function names(array $networks): string
    {
        return implode(', ', array_map(fn (SocialNetwork $n) => $n->label(), $networks));
    }

    /**
     * @param  list<SocialNetwork>  $networks
     * @return list<string>
     */
    private function values(array $networks): array
    {
        return array_map(fn (SocialNetwork $n) => $n->value, $networks);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    private function list(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }
}
