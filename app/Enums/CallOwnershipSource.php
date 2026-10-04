<?php

namespace App\Enums;

/**
 * How a call log was attributed to an organization (docs/decisions.md D2).
 */
enum CallOwnershipSource: string
{
    /** Logged by an agent for a specific organization. */
    case Direct = 'direct';

    /** Backfilled from the legacy client_id column. */
    case ClientId = 'client_id';

    /** Backfilled because the caller email matched exactly one client. Needs admin review. */
    case EmailMatch = 'email_match';

    /** Could not be attributed. Visible to platform admins only until assigned. */
    case Unassigned = 'unassigned';

    /** Attribution confirmed or set by a platform admin in the call review queue. */
    case Reviewed = 'reviewed';

    public function needsReview(): bool
    {
        return in_array($this, [self::EmailMatch, self::Unassigned], true);
    }
}
