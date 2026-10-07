<?php

namespace App\Support\Navigation;

use App\Models\User;
use App\Services\Billing\FeatureAccess;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Route;

/**
 * Sidebar navigation per portal (spec §6), filtered by permission.
 *
 * Items without a built route are shown as "Coming soon" (spec §95) and kept to
 * the core set for clients (spec §119) so new customers aren't overwhelmed.
 */
class PortalNavigation
{
    /**
     * @return list<array{label: string, icon: string, route: ?string, active: string, permission: ?string, status: string, feature?: ?string}>
     */
    public function definition(string $portal): array
    {
        return match ($portal) {
            'client' => [
                $this->item('Dashboard', 'home', 'app.dashboard', 'app.dashboard', 'dashboard.view'),
                $this->item('Results', 'trend-up', 'app.results', 'app.results*', 'reports.view'),
                $this->item('Calls', 'phone', 'app.calls.index', 'app.calls.*', 'calls.view'),
                $this->item('Calendar', 'calendar', 'app.calendar', 'app.calendar', 'calls.view'),
                $this->item('Appointments', 'list', 'app.appointments.index', 'app.appointments.*', 'appointments.view'),
                $this->item('Customers', 'users', 'app.customers.index', 'app.customers.*', 'customers.view'),
                $this->item('Tasks', 'check-circle', 'app.tasks.index', 'app.tasks.*', 'tasks.view'),
                $this->item('Escalations', 'alert', 'app.escalations.index', 'app.escalations.*', 'escalations.view'),
                $this->item('Social', 'megaphone', 'app.social.index', 'app.social.*', 'social.view', 'social_publishing'),
                $this->item('Inbox', 'chat', 'app.inbox.index', 'app.inbox.*', 'messages.view'),
                $this->item('Business', 'building', 'app.business.profile', 'app.business.*', 'organization.view'),
                $this->item('Website', 'globe', 'app.website', 'app.website*', 'integrations.view', 'website_tools'),
                $this->item('Billing', 'card', 'app.billing', 'app.billing*', 'billing.view'),
                $this->item('Team', 'users', 'app.settings.team', 'app.settings.team', 'users.view'),
                $this->item('Notifications', 'bell', 'app.settings.notifications', 'app.settings.notifications', null),
                $this->item('Data & privacy', 'shield', 'app.settings.privacy', 'app.settings.privacy*', 'organization.update'),
                $this->item('Support', 'info', 'app.support', 'app.support*', 'support.view'),
            ],
            'admin' => [
                $this->item('Overview', 'home', 'admin.home', 'admin.home', 'dashboard.view'),
                $this->item('Organizations', 'building', 'admin.organizations.index', 'admin.organizations.*', 'organization.view'),
                $this->item('Users', 'user', 'admin.users', 'admin.users', 'users.view'),
                $this->item('Duty schedule', 'calendar', 'admin.schedule', 'admin.schedule', 'users.view'),
                $this->item('Calls', 'phone', 'admin.calls', 'admin.calls', 'calls.view'),
                $this->item('Appointments', 'calendar', 'admin.appointments', 'admin.appointments', 'appointments.view'),
                $this->item('Customers', 'users', 'admin.customers', 'admin.customers', 'customers.view'),
                $this->item('Call review', 'inbox', 'admin.calls.review', 'admin.calls.review', 'calls.update'),
                $this->item('Escalations', 'alert', 'admin.escalations', 'admin.escalations', 'escalations.view'),
                $this->item('Support', 'info', 'admin.support', 'admin.support*', 'support.manage'),
                $this->item('Call quality', 'check-circle', 'agent.quality', 'agent.quality', 'qa.review'),
                $this->item('Billing', 'card', 'admin.billing', 'admin.billing', 'billing.view'),
                $this->item('Usage', 'chart', 'admin.usage', 'admin.usage', 'billing.view'),
                $this->item('Website enquiries', 'chat', 'admin.enquiries', 'admin.enquiries', 'marketing.view'),
                $this->item('Audit log', 'shield', 'admin.audit', 'admin.audit', 'audit_logs.view'),
                $this->item('Agent assignments', 'users', 'agent.assignments', 'agent.assignments|agent.team*', 'agent_assignments.view'),
                $this->item('Agent University', 'sparkles', 'agent.training.courses', 'agent.training.*', 'training.view_progress'),
                $this->item('Agent workspace', 'phone', 'agent.home', 'agent.home|agent.companies|agent.businesses.*|agent.calls*|agent.schedule', 'calls.create'),
            ],
            'agent' => [
                $this->item('Today', 'home', 'agent.home', 'agent.home', 'calls.create'),
                $this->item('My companies', 'building', 'agent.companies', 'agent.companies|agent.businesses.*', 'calls.create'),
                $this->item('My calls', 'phone', 'agent.calls', 'agent.calls*', 'calls.create'),
                $this->item('My schedule', 'calendar', 'agent.schedule', 'agent.schedule', null),
                $this->item('Call quality', 'check-circle', 'agent.quality', 'agent.quality', null),
                $this->item('University', 'sparkles', 'agent.university', 'agent.university*', 'agent_university.view'),
                $this->item('Team', 'users', 'agent.team', 'agent.team*', 'agent_assignments.view'),
                $this->item('Assignments', 'list', 'agent.assignments', 'agent.assignments', 'agent_assignments.view'),
                $this->item('Training', 'chart', 'agent.training.courses', 'agent.training.*', 'training.view_progress'),
            ],
            default => [],
        };
    }

    /**
     * Items the user may see, with `current` resolved for the active route.
     *
     * @return list<array<string, mixed>>
     */
    public function for(User $user, string $portal): array
    {
        $items = [];

        foreach ($this->definition($portal) as $item) {
            if ($item['permission'] && ! $user->hasPermissionIn($item['permission'])) {
                continue;
            }

            $item['url'] = $item['route'] && Route::has($item['route']) ? route($item['route']) : null;
            // A paid feature this business doesn't have: still listed, marked "Upgrade" (D46).
            $organization = app(CurrentOrganization::class)->get();
            $item['locked'] = ! empty($item['feature']) && $organization && ! app(FeatureAccess::class)->allows($organization, $item['feature']);
            $item['current'] = $item['route'] !== null && request()->routeIs(...explode('|', $item['active']));
            $items[] = $item;
        }

        return $items;
    }

    private function item(string $label, string $icon, string $route, string $active, ?string $permission, ?string $feature = null): array
    {
        return compact('label', 'icon', 'route', 'active', 'permission', 'feature') + ['status' => 'live'];
    }
}
