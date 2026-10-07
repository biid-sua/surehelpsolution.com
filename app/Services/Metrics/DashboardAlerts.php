<?php

namespace App\Services\Metrics;

use App\Enums\SubscriptionStatus;
use App\Models\CalendarConnection;
use App\Models\Conversation;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\User;
use App\Services\Billing\FeatureAccess;

/**
 * Things on the client dashboard that need someone (spec §8.1 "Alerts"). Each one is a real condition
 * with a link to fix it, shown only to people allowed to act on it. Nothing here is a reminder for its own sake.
 */
class DashboardAlerts
{
    /** A message waiting longer than this without a reply counts as unanswered. */
    public const UNANSWERED_MINUTES = 30;

    public function __construct(private readonly FeatureAccess $features) {}

    /**
     * @return list<array{key: string, tone: string, title: string, body: string, url: string, action: string}>
     */
    public function for(Organization $organization, User $user): array
    {
        $alerts = [];
        $can = fn (string $permission) => $user->can($permission, $organization);

        if ($can('integrations.view') && $this->features->allows($organization, 'calendar_sync')) {
            $broken = CalendarConnection::query()->forOrganization($organization)
                ->whereIn('status', [CalendarConnection::STATUS_NEEDS_REAUTH, CalendarConnection::STATUS_ERROR])->get(['id', 'provider', 'account_email']);
            if ($broken->isNotEmpty()) {
                $alerts[] = ['key' => 'calendar', 'tone' => 'danger', 'title' => 'Your calendar is disconnected',
                    'body' => 'Bookings stop reaching '.($broken->first()->account_email ?: 'your calendar').' and your busy times no longer block bookings until you reconnect.',
                    'url' => route('app.business.calendars'), 'action' => 'Reconnect'];
            }
        }

        if ($can('billing.view')) {
            $overdue = Invoice::query()->forOrganization($organization)->outstanding()->where('due_at', '<', now())->count();
            $pastDue = Subscription::query()->forOrganization($organization)->where('status', SubscriptionStatus::PastDue->value)->exists();
            if ($overdue > 0 || $pastDue) {
                $alerts[] = ['key' => 'billing', 'tone' => 'danger', 'title' => $overdue > 0 ? $overdue.' overdue '.str('invoice')->plural($overdue) : 'Your payment is overdue',
                    'body' => 'Please pay to keep your service running without interruption.', 'url' => route('app.billing'), 'action' => 'Open billing'];
            }
        }

        if ($can('messages.view')) {
            $waiting = Conversation::query()->forOrganization($organization)->where('status', 'open')
                ->where('last_inbound_at', '<', now()->subMinutes(self::UNANSWERED_MINUTES))
                ->whereColumn('last_inbound_at', '>=', 'last_message_at')
                ->count();
            if ($waiting > 0) {
                $alerts[] = ['key' => 'messages', 'tone' => 'warning', 'title' => $waiting.' '.str('message')->plural($waiting).' waiting for a reply',
                    'body' => 'Customers wrote more than '.self::UNANSWERED_MINUTES.' minutes ago and haven\'t heard back.', 'url' => route('app.inbox.index'), 'action' => 'Open inbox'];
            }
        }

        if ($can('tasks.view')) {
            $late = Task::query()->forOrganization($organization)->overdue()->count();
            if ($late > 0) {
                $alerts[] = ['key' => 'tasks', 'tone' => 'warning', 'title' => $late.' overdue '.str('follow-up')->plural($late),
                    'body' => 'They were due and are still open.', 'url' => route('app.tasks.index', ['view' => 'overdue']), 'action' => 'See them'];
            }
        }

        return $alerts;
    }
}
