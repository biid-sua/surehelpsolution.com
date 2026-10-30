<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Providers\ClaudeProvider;
use App\Services\Ai\Providers\OpenAiProvider;

/**
 * Which AI vendor answers (D104). `config('ai.provider')` picks the one bound to AiProvider for every
 * feature; SureHelp's own social posts may use the other (`ai.social_provider`).
 */
class AiProviders
{
    public const LABELS = ['claude' => 'Claude', 'openai' => 'ChatGPT'];

    public function make(?string $key = null): AiProvider
    {
        return match ($key ?? config('ai.provider')) {
            'openai' => app(OpenAiProvider::class),
            default => app(ClaudeProvider::class),
        };
    }

    /** The one writing SureHelp's own social posts. Tests that bind AiProvider get that instead. */
    public function forSocial(): AiProvider
    {
        $key = config('ai.social_provider');

        return filled($key) && $key !== config('ai.provider') ? $this->make($key) : app(AiProvider::class);
    }

    public function label(?string $key = null): string
    {
        return self::LABELS[$key ?? config('ai.provider')] ?? 'Claude';
    }
}
