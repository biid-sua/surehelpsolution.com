<?php

namespace App\Services\Ai\Contracts;

use App\Exceptions\AiUnavailable;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;

/**
 * One AI vendor (spec §33: provider abstraction). The assistant's loop, tools and guardrails are
 * vendor-neutral; only this sits between them and the vendor's API.
 */
interface AiProvider
{
    /** An API key (or equivalent) is set. Without it no AI feature runs. */
    public function isConfigured(): bool;

    public function model(): string;

    /**
     * One model call. Tool calls come back in the response; the caller runs them and calls again.
     *
     * @throws AiUnavailable when the vendor can't be reached or refuses the request
     */
    public function complete(AiRequest $request): AiResponse;
}
