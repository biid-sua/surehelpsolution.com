<?php

namespace App\Services\Seo;

use App\Exceptions\AiUnavailable;
use App\Jobs\RunSeoTask;
use App\Models\Organization;
use App\Models\SeoTask;
use App\Models\User;
use App\Models\Website;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Billing\FeatureAccess;
use App\Support\Audit\Audit;
use Illuminate\Validation\ValidationException;

/**
 * Runs SEO tasks with AI for a business (D57). A task is queued, then the model writes the work
 * product as structured JSON; the tokens it used count against the business's monthly allowance.
 * The business reviews the result; SureHelp staff (or the business) put it on the site and mark it
 * applied, which marks the date on the statistics so the effect can be seen.
 */
class SeoDesk
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly SeoTokens $tokens,
        private readonly SeoTaskCatalog $catalog,
        private readonly SeoFacts $facts,
        private readonly FeatureAccess $features,
        private readonly Audit $audit,
    ) {}

    /** Why a new task can't start now, or null. */
    public function blocker(Organization $organization, string $type): ?string
    {
        return match (true) {
            ! $this->features->allows($organization, 'seo_service') => 'The SEO service isn\'t switched on for this business.',
            ! $this->ai->isConfigured() => 'The AI isn\'t set up yet. Please contact SureHelp support.',
            ! $this->tokens->canStart($organization) => 'This month\'s AI allowance for SEO is used up. It resets on the 1st.',
            ($this->catalog::TYPES[$type]['needs_pages'] ?? false) && $this->pages($organization) === [] => 'Add and verify your website under Website first, so we know its pages.',
            SeoTask::withoutGlobalScopes()->where('organization_id', $organization->id)->whereIn('status', ['queued', 'running'])->count() >= 3 => 'Three tasks are already running. Try again in a minute.',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function request(Organization $organization, string $type, array $input, ?User $by, string $source = 'client'): SeoTask
    {
        if (! isset(SeoTaskCatalog::TYPES[$type])) {
            throw ValidationException::withMessages(['type' => ['Choose a task.']]);
        }
        if ($why = $this->blocker($organization, $type)) {
            throw ValidationException::withMessages(['type' => [$why]]);
        }

        $website = $this->website($organization);
        if ($type === 'page_meta') {
            $input['pages'] = array_slice($this->pages($organization), 0, 10);
        }

        $task = SeoTask::create([
            'organization_id' => $organization->id,
            'website_id' => $website?->id,
            'type' => $type,
            'source' => $source,
            'input' => $input ?: null,
            'requested_by_user_id' => $by?->id,
        ]);
        $this->audit->record('seo.task_requested', $task, new: ['type' => $type, 'source' => $source], organization: $organization, actor: $by,
            label: SeoTaskCatalog::TYPES[$type]['label']);
        RunSeoTask::dispatch($task->id);

        return $task;
    }

    public function run(SeoTask $task): SeoTask
    {
        if ($task->status !== 'queued') {
            return $task;
        }
        $organization = Organization::query()->findOrFail($task->organization_id);
        $task->forceFill(['status' => 'running'])->save();

        try {
            $request = $this->catalog->request($task, $task->website_id ? Website::withoutGlobalScopes()->find($task->website_id) : null);
            $response = $this->ai->complete(new AiRequest(
                $this->catalog->instructions($this->facts->for($organization)),
                '',
                [['role' => 'user', 'content' => $request['prompt']]],
                outputSchema: $request['schema'],
                effort: (string) config('seo.effort', 'high'),
            ));
            $used = ['model' => $this->ai->model(), 'input_tokens' => $response->usage['input'] + $response->usage['cache_read'], 'output_tokens' => $response->usage['output']];

            if ($response->stopReason !== 'end_turn' || ! is_array($data = json_decode($response->text, true))) {
                $why = match ($response->stopReason) {
                    'refusal' => 'The AI declined this request.',
                    'max_tokens' => 'The answer was too long and got cut off. Try a narrower request.',
                    default => 'The AI\'s answer couldn\'t be read.',
                };

                $task->forceFill($used + ['status' => 'failed', 'error' => $why])->save();

                return $task;
            }

            $task->forceFill($used + ['status' => 'done', 'output' => $this->catalog->tidy($task->type, $data), 'error' => null])->save();
        } catch (AiUnavailable $e) {
            report($e);
            $task->forceFill(['status' => 'failed', 'error' => 'The AI couldn\'t be reached. Try again in a few minutes; no tokens were used.'])->save();
        }

        return $task;
    }

    public function website(Organization $organization): ?Website
    {
        return Website::withoutGlobalScopes()->where('organization_id', $organization->id)->whereNotNull('verified_at')
            ->orderByDesc('health_score')->orderBy('id')->first();
    }

    /** @return list<array{url: string, title: ?string, description: ?string, h1: ?string}> */
    public function pages(Organization $organization): array
    {
        return $this->website($organization)?->health['pages'] ?? [];
    }
}
