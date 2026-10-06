<?php

namespace App\Services\Training;

use App\Models\Organization;
use App\Models\User;

/**
 * Whether an agent has completed the training a company requires (spec §20B, D43).
 * Until Agent University's requirements exist (A-3/A-4), every agent is ready: there is nothing to require.
 */
class TrainingReadiness
{
    /**
     * @return array{ready: bool, required: int, missing: list<string>, enforcement: ?string}
     */
    public function for(User $agent, Organization $organization): array
    {
        return ['ready' => true, 'required' => 0, 'missing' => [], 'enforcement' => null];
    }
}
