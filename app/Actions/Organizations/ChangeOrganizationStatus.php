<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\ServiceStatusChanged;
use App\Support\Audit\Audit;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SureHelp staff switch a business's service on, pause it or cancel it (ADM-02, ONB-11, D45).
 *
 * Onboarding → active ("go live"), active ⇄ paused, any → cancelled, cancelled → active (reactivate).
 * Pausing and cancelling need a reason, which the owner sees. While paused or cancelled, agents no
 * longer see the business and the website snippet stays hidden; the owner keeps access to their data.
 * An account the owner closed (D29) can't be reactivated.
 */
class ChangeOrganizationStatus
{
    /** Allowed moves: from => [to, ...]. */
    public const TRANSITIONS = [
        'onboarding' => ['active', 'paused', 'cancelled'],
        'active' => ['paused', 'cancelled'],
        'paused' => ['active', 'cancelled'],
        'cancelled' => ['active'],
    ];

    public function __construct(private readonly Audit $audit) {}

    public function handle(Organization $organization, OrganizationStatus $to, User $actor, ?string $reason = null): Organization
    {
        $from = $organization->status;
        if (! in_array($to->value, self::TRANSITIONS[$from->value], true)) {
            throw ValidationException::withMessages(['status' => "A business that is {$from->label()} can't be set to {$to->label()}."]);
        }
        if ($organization->closed_at !== null) {
            throw ValidationException::withMessages(['status' => 'The owner closed this account and its data was deleted. It can\'t be reactivated.']);
        }

        $reason = filled($reason) ? Str::limit(trim((string) $reason), 500, '') : null;
        if (in_array($to, [OrganizationStatus::Paused, OrganizationStatus::Cancelled], true) && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'Say why. The owner sees this.']);
        }

        $organization->forceFill(['status' => $to, 'status_reason' => $reason, 'status_changed_at' => now()])->save();
        $this->audit->record('organization.status_changed', $organization, ['status' => $from->value], ['status' => $to->value, 'reason' => $reason],
            actor: $actor, label: $organization->name);

        $owner = $organization->owner;
        if ($owner && $owner->is_active) {
            $owner->notify(new ServiceStatusChanged($organization, $from));
        }

        return $organization;
    }
}
