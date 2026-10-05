<?php

namespace App\Services\Social;

use App\Models\BusinessProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Account\Impersonation;

/**
 * Who must approve a post before it goes out (spec §41). With the default "owner" setting, posts by
 * anyone but an owner (managers, SureHelp's team viewing as the business, AI) wait for an owner.
 */
class SocialApproval
{
    public const MODES = [
        'owner' => 'Posts by anyone other than an owner need an owner\'s approval',
        'off' => 'Anyone who can manage social posts can publish directly',
    ];

    public function mode(Organization $organization): string
    {
        $mode = BusinessProfile::query()->forOrganization($organization)->value('social_approval');

        return array_key_exists((string) $mode, self::MODES) ? (string) $mode : 'owner';
    }

    /** Someone from SureHelp is using the portal as this person ("view as client"). */
    public function byOurTeam(): bool
    {
        return app()->bound('request') && app(Impersonation::class)->active(request());
    }

    public function isOwner(Organization $organization, User $user): bool
    {
        return ! $this->byOurTeam() && $user->organizationRole($organization) === 'owner';
    }

    public function needsApproval(Organization $organization, User $author, string $source = 'manual'): bool
    {
        if ($this->mode($organization) === 'off' && $source !== 'ai') {
            return false;
        }

        return $source === 'ai' || ! $this->isOwner($organization, $author);
    }

    public function canApprove(Organization $organization, User $user): bool
    {
        return $this->isOwner($organization, $user);
    }
}
