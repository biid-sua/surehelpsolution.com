<?php

namespace App\Services\Ai;

use App\Exceptions\AiUnavailable;
use App\Models\AiAssistant;
use App\Models\AiRun;
use App\Models\Organization;
use App\Services\Ai\Assistant\BusinessBrain;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Calls\CallOutcomes;
use App\Services\Compliance\Hipaa;
use Illuminate\Validation\ValidationException;

/**
 * AI help for agents during a call (D88): answers from the business's own facts that an agent can read
 * out, and the call form filled in from the agent's notes. Uses the same business briefing as the
 * messaging assistant (so the provider's prompt cache is shared), with a small daily cap per business.
 */
class AgentAssist
{
    public function __construct(
        private readonly AiProvider $ai,
        private readonly BusinessBrain $brain,
        private readonly CallOutcomes $outcomes,
    ) {}

    public function blocker(Organization $organization): ?string
    {
        $used = AiRun::withoutGlobalScopes()->where('organization_id', $organization->id)->where('mode', 'assist')->where('created_at', '>=', now()->startOfDay())->count();

        return match (true) {
            Hipaa::on($organization) => 'AI help is off for this business (HIPAA mode).',
            ! $this->ai->isConfigured() => 'AI help isn\'t switched on yet.',
            $used >= (int) config('ai.agent_assist_daily', 300) => 'Today\'s AI help for this business is used up. Use the knowledge list.',
            default => null,
        };
    }

    /**
     * @return array{answer: string, confident: bool}
     *
     * @throws ValidationException
     */
    public function answer(Organization $organization, string $question): array
    {
        $data = $this->ask($organization, "# Right now\nYou are NOT talking to a customer. You are helping a SureHelp call agent who is on the phone for this business. "
            ."Answer the caller's question in 1 to 3 short sentences the agent can read out, using only the facts above. "
            ."If the facts don't answer it, say so in confident: false and suggest what the agent should do (take a message, offer a call back). Never invent prices, times or policies.",
            'The caller asks: '.mb_substr(trim($question), 0, 500),
            ['type' => 'object', 'properties' => ['answer' => ['type' => 'string'], 'confident' => ['type' => 'boolean']], 'required' => ['answer', 'confident'], 'additionalProperties' => false]);

        return ['answer' => trim((string) ($data['answer'] ?? '')), 'confident' => (bool) ($data['confident'] ?? false)];
    }

    /**
     * The call form's fields, worked out from the agent's notes. Missing ones are empty strings.
     *
     * @return array{name: string, phone: string, email: string, reason: string, outcome: string, notes: string}
     *
     * @throws ValidationException
     */
    public function fill(Organization $organization, string $notes): array
    {
        $menu = $this->outcomes->menu($organization);
        $keys = array_column($menu, 'key');
        $data = $this->ask($organization, "# Right now\nYou are NOT talking to a customer. A SureHelp agent typed quick notes during a call for this business. "
            .'Fill the call form from them. Use only what the notes say; leave a field empty when it isn\'t there. '
            .'reason: a few words. notes: a clean summary for the business, 1 to 3 sentences. outcome: the best match from this list, or empty: '
            .implode(', ', array_map(fn (array $o) => $o['key'].' ('.$o['label'].')', $menu)).'.',
            "Agent's notes:\n".mb_substr(trim($notes), 0, 3000),
            ['type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'phone', 'email', 'reason', 'outcome', 'notes'], 'properties' => [
                'name' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'email' => ['type' => 'string'],
                'reason' => ['type' => 'string'], 'outcome' => ['type' => 'string'], 'notes' => ['type' => 'string'],
            ]]);
        $clean = fn (string $k, int $max) => mb_substr(trim((string) ($data[$k] ?? '')), 0, $max);

        return [
            'name' => $clean('name', 120), 'phone' => $clean('phone', 40),
            'email' => filter_var($clean('email', 190), FILTER_VALIDATE_EMAIL) ?: '',
            'reason' => $clean('reason', 200),
            'outcome' => in_array($data['outcome'] ?? '', $keys, true) ? (string) $data['outcome'] : '',
            'notes' => $clean('notes', 2000),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function ask(Organization $organization, string $context, string $prompt, array $schema): array
    {
        if ($blocker = $this->blocker($organization)) {
            throw ValidationException::withMessages(['assist' => [$blocker]]);
        }
        try {
            $response = $this->ai->complete(new AiRequest(
                $this->brain->instructions($organization, AiAssistant::for($organization), false), $context,
                [['role' => 'user', 'content' => $prompt]], outputSchema: $schema, effort: 'low',
                model: config('ai.agent_assist_model') ?: null, maxTokens: 700,
            ));
        } catch (AiUnavailable) {
            throw ValidationException::withMessages(['assist' => ['The AI service is unavailable right now. Use the knowledge list.']]);
        }
        AiRun::create(['organization_id' => $organization->id, 'mode' => 'assist', 'model' => $this->ai->model(), 'status' => 'drafted',
            'input_tokens' => $response->usage['input'] + $response->usage['cache_read'], 'output_tokens' => $response->usage['output'],
            'cache_read_tokens' => $response->usage['cache_read']]);
        $data = $response->stopReason === 'end_turn' ? json_decode($response->text, true) : null;
        if (! is_array($data)) {
            throw ValidationException::withMessages(['assist' => ['The AI couldn\'t help with that one. Use the knowledge list.']]);
        }

        return $data;
    }
}
