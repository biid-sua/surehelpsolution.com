<?php

namespace App\Actions\Notifications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as Notifier;

/**
 * Sends a notification to the active members of an organization who hold the
 * permission needed to see what it's about. Notifications never cross tenants.
 */
class NotifyOrganization
{
    /**
     * @return Collection<int, User> the recipients
     */
    public function handle(Organization $organization, Notification $notification, string $requiredPermission, ?User $except = null): Collection
    {
        $recipients = $organization->members()
            ->wherePivot('status', 'active')
            ->where('users.is_active', true)
            ->with('notificationPreferences')
            ->get()
            ->filter(fn (User $member) => $member->id !== $except?->id && $member->hasPermissionIn($requiredPermission, $organization))
            ->values();

        if ($recipients->isNotEmpty()) {
            Notifier::send($recipients, $notification);
        }

        return $recipients;
    }
}
