<?php

namespace App\Actions\Calls;

use App\Enums\CallOwnershipSource;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Admin review queue (docs/decisions.md D2): confirm a flagged email match, or
 * attach an unassigned call to the right organization.
 */
class AttributeCall
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(User $reviewer, CallLog $call, Organization $organization): CallLog
    {
        if (! $reviewer->isAdmin() || ! $reviewer->hasPermissionIn('calls.update', $organization)) {
            throw new AuthorizationException('You are not allowed to attribute calls.');
        }

        $call->forceFill([
            'organization_id' => $organization->getKey(),
            'ownership_source' => CallOwnershipSource::Reviewed,
        ])->save();

        $this->audit->changes('call.attributed', $call, ['organization_id', 'ownership_source'], $organization);

        return $call;
    }
}
