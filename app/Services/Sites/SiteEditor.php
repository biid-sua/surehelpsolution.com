<?php

namespace App\Services\Sites;

use App\Exceptions\AiUnavailable;
use App\Jobs\RunSiteEdit;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteEdit;
use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Billing\AiCredits;
use App\Services\Billing\Entitlements;
use App\Services\Seo\SeoFacts;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Changes a hosted website from a request in plain words (D99): "make the headline about emergency
 * repairs", "add a page about drain cleaning". The AI gets the site's content and the business's
 * facts and answers with the whole new content, in the fixed shape of SiteContent, so it edits
 * data, never HTML. The result is a new draft version: nothing goes live until the owner publishes.
 *
 * Tokens are counted per calendar month against an allowance (the plan's `site_ai_tokens` limit, or
 * the default).
 */
class SiteEditor
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly SiteBuilder $builder,
        private readonly SeoFacts $facts,
        private readonly Entitlements $entitlements,
        private readonly Audit $audit,
        private readonly AiCredits $credits,
    ) {}

    public function allowance(Organization $organization): int
    {
        return $this->entitlements->limit($organization, 'site_ai_tokens') ?? (int) config('sites.monthly_tokens', 150000);
    }

    public function used(Organization $organization): int
    {
        $start = CarbonImmutable::now($organization->timezoneOrDefault())->startOfMonth()->utc();

        return (int) SiteEdit::withoutGlobalScopes()->where('organization_id', $organization->id)->where('created_at', '>=', $start)
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0) as total')->value('total');
    }

    public function remaining(Organization $organization): int
    {
        return max(0, $this->allowance($organization) - $this->used($organization));
    }

    /** Tokens a change may use: what's left this month, plus credits (D100). */
    public function available(Organization $organization): int
    {
        return $this->remaining($organization) + max(0, $this->credits->balance($organization)) * $this->credits->tokensPerCredit();
    }

    public function blocker(Site $site): ?string
    {
        $organization = $site->organization;

        return match (true) {
            ! $this->ai->isConfigured() => 'The AI isn\'t set up yet. Please contact SureHelp support.',
            $this->available($organization) < (int) config('sites.min_tokens_to_start', 8000) => 'This month\'s AI allowance for your website is used up. Buy AI credits under Billing to keep going, or wait until the 1st.',
            SiteEdit::withoutGlobalScopes()->where('site_id', $site->id)->whereIn('status', ['queued', 'running'])->exists() => 'A change is already being made. Wait for it to finish, then ask for the next one.',
            default => null,
        };
    }

    /** @throws ValidationException */
    public function request(Site $site, string $prompt, string $page, User $by): SiteEdit
    {
        $prompt = trim($prompt);
        if (mb_strlen($prompt) < 3) {
            throw ValidationException::withMessages(['prompt' => 'Tell us what to change, e.g. "Make the headline about emergency repairs".']);
        }
        if ($why = $this->blocker($site)) {
            throw ValidationException::withMessages(['prompt' => $why]);
        }

        $edit = SiteEdit::create([
            'site_id' => $site->id, 'organization_id' => $site->organization_id, 'prompt' => Str::limit($prompt, 2000, ''),
            'page' => SiteContent::page($site->draft()->content, $page) ? $page : '', 'user_id' => $by->id,
        ]);
        $this->audit->record('site.edit_requested', $edit, new: ['prompt' => Str::limit($prompt, 200)], organization: $site->organization, actor: $by, label: $site->subdomain);
        RunSiteEdit::dispatch($edit->id);

        return $edit;
    }

    public function run(SiteEdit $edit): SiteEdit
    {
        if ($edit->status !== 'queued') {
            return $edit;
        }
        $edit->forceFill(['status' => 'running'])->save();
        $site = Site::withoutGlobalScopes()->with('organization')->findOrFail($edit->site_id);
        // What's left of the monthly allowance decides how much of this change credits pay for (D100).
        $allowanceLeft = $this->remaining($site->organization);
        $draft = $site->draft();

        try {
            $response = $this->ai->complete(new AiRequest(
                $this->instructions($this->facts->for($site->organization)),
                '',
                [['role' => 'user', 'content' => $this->prompt($draft->content, $edit)]],
                outputSchema: ['type' => 'object', 'additionalProperties' => false, 'required' => ['summary', 'changed', 'content'], 'properties' => [
                    'summary' => ['type' => 'string'],
                    'changed' => ['type' => 'boolean'],
                    'content' => SiteContent::schema(),
                ]],
                effort: (string) config('sites.effort', 'medium'),
                maxTokens: (int) config('sites.max_output_tokens', 16000),
            ));
            $used = ['model' => $this->ai->model(), 'input_tokens' => $response->usage['input'] + $response->usage['cache_read'], 'output_tokens' => $response->usage['output']];
            $data = json_decode($response->text, true);

            if ($response->stopReason !== 'end_turn' || ! is_array($data) || ! is_array($data['content'] ?? null)) {
                $why = match ($response->stopReason) {
                    'refusal' => 'The AI declined this change.',
                    'max_tokens' => 'The change was too big to finish. Try asking for one part at a time.',
                    default => 'The AI\'s answer couldn\'t be read. Please try again.',
                };

                $this->finish($edit, $used + ['status' => 'failed', 'error' => $why], $allowanceLeft);

                return $edit;
            }

            $summary = Str::limit(trim((string) ($data['summary'] ?? '')), 500, '') ?: 'Updated the website.';
            $content = SiteContent::normalize($data['content']);
            if (! ($data['changed'] ?? true) || $content === SiteContent::normalize($draft->content)) {
                $this->finish($edit, $used + ['status' => 'nothing', 'summary' => $summary], $allowanceLeft);

                return $edit;
            }

            $version = $this->builder->addVersion($site, $content, Str::limit($edit->prompt, 200), 'ai', $edit->user);

            $this->finish($edit, $used + ['status' => 'done', 'summary' => $summary, 'version_id' => $version->id], $allowanceLeft);

            return $edit;
        } catch (AiUnavailable $e) {
            report($e);

            $edit->forceFill(['status' => 'failed', 'error' => 'The AI couldn\'t be reached. Try again in a few minutes; no tokens were used.'])->save();

            return $edit;
        }
    }

    /**
     * Saves the outcome and charges credits for tokens beyond the allowance.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function finish(SiteEdit $edit, array $attributes, int $allowanceLeft): void
    {
        $edit->forceFill($attributes)->save();
        $this->credits->chargeSiteEdit($edit, $allowanceLeft);
    }

    private function instructions(string $facts): string
    {
        $types = collect(SiteContent::TYPES)->map(fn (string $what, string $type) => "- {$type}: {$what}")->implode("\n");
        $pages = (int) config('sites.max_pages', 8);
        $sections = (int) config('sites.max_sections', 14);

        return <<<TXT
        You edit a small business's website for its owner. The website is JSON content, not HTML: a
        theme and pages made of sections. You receive the current content and the owner's request, and
        answer with the whole content after the change.

        Rules:
        - Change only what the request is about. Keep every other page, section, id and word as it is.
        - Write plain text only: no HTML, no Markdown, no emoji unless asked. Short sentences, US spelling,
          the business speaking as "we".
        - Use only facts from the business facts below or the owner's request. Never invent prices,
          licenses, years in business, guarantees, awards, reviews, addresses or phone numbers. If the
          request needs a fact you don't have, write around it and say so in the summary.
        - Reviews must be real: only keep or reword reviews already on the site, never write new ones.
        - The first page is the home page and its slug is "". Other slugs are short, lowercase, with dashes.
          At most {$pages} pages and {$sections} sections a page. Give new sections a new short id.
        - Phone, email, address and opening hours are filled in automatically by the "contact" and "hours"
          sections; don't type them into text. Buttons book online or call; only their label is yours.
        - theme.accent is a #rrggbb colour; theme.style is bold, clean or warm.
        - seo_title is up to 60 characters and seo_description up to 155, written for search results.
        - If the request is unclear, unsafe, illegal, or not about the website, make no change: set
          "changed" to false and explain in the summary.
        - "summary" tells the owner in one or two plain sentences what you changed.

        Section types (every section has heading, text, button and items of title and text; leave fields
        a type doesn't use empty):
        {$types}

        Business facts:
        {$facts}
        TXT;
    }

    /** @param  array<string, mixed>  $content */
    private function prompt(array $content, SiteEdit $edit): string
    {
        $page = SiteContent::page($content, $edit->page);
        $where = $page ? 'The owner was looking at the page "'.$page['title'].'" (slug "'.$edit->page.'").' : '';

        return "Current website content:\n".json_encode(SiteContent::normalize($content), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ."\n\n{$where}\nThe owner's request:\n".$edit->prompt;
    }
}
