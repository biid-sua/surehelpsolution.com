<?php

namespace App\Support\Navigation;

use App\Models\User;
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
     * @return list<array{label: string, icon: string, route: ?string, active: string, permission: ?string, status: string}>
     */
    public function definition(string $portal): array
    {
        return match ($portal) {
            'client' => [
                $this->item('Dashboard', 'home', 'app.dashboard', 'app.dashboard', 'dashboard.view'),
                $this->item('Calls', 'phone', 'app.calls.index', 'app.calls.*', 'calls.view'),
                $this->item('Calendar', 'calendar', 'app.calendar', 'app.calendar', 'calls.view'),
                $this->item('Appointments', 'list', 'app.appointments.index', 'app.appointments.*', 'appointments.view'),
                $this->item('Customers', 'users', 'app.customers.index', 'app.customers.*', 'customers.view'),
                $this->item('Tasks', 'check-circle', 'app.tasks.index', 'app.tasks.*', 'tasks.view'),
                $this->item('Escalations', 'alert', 'app.escalations.index', 'app.escalations.*', 'escalations.view'),
                $this->soon('Messages', 'chat'),
                $this->item('Business', 'building', 'app.business.profile', 'app.business.*', 'organization.view'),
                $this->item('Billing', 'card', 'app.billing', 'app.billing*', 'billing.view'),
                $this->item('Notifications', 'bell', 'app.settings.notifications', 'app.settings.*', null),
            ],
            'admin' => [
                $this->item('Overview', 'home', 'admin.home', 'admin.home', 'dashboard.view'),
                $this->item('Organizations', 'building', 'admin.organizations.index', 'admin.organizations.*', 'organization.view'),
                $this->item('Users', 'user', 'admin.users', 'admin.users', 'users.view'),
                $this->item('Duty schedule', 'calendar', 'admin.schedule', 'admin.schedule', 'users.view'),
                $this->item('Call review', 'inbox', 'admin.calls.review', 'admin.calls.review', 'calls.update'),
                $this->item('Escalations', 'alert', 'admin.escalations', 'admin.escalations', 'escalations.view'),
                $this->item('Billing', 'card', 'admin.billing', 'admin.billing', 'billing.view'),
                $this->item('Website enquiries', 'chat', 'admin.enquiries', 'admin.enquiries', 'marketing.view'),
                $this->item('Audit log', 'shield', 'admin.audit', 'admin.audit', 'audit_logs.view'),
                $this->item('Agent workspace', 'phone', 'agent.home', 'agent.*', 'calls.create'),
            ],
            'agent' => [
                $this->item('Businesses', 'building', 'agent.home', 'agent.home|agent.businesses.*', 'calls.create'),
                $this->item('My calls', 'phone', 'agent.calls', 'agent.calls*', 'calls.create'),
                $this->item('My schedule', 'calendar', 'agent.schedule', 'agent.schedule', null),
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
            $item['current'] = $item['route'] !== null && request()->routeIs(...explode('|', $item['active']));
            $items[] = $item;
        }

        return $items;
    }

    private function item(string $label, string $icon, string $route, string $active, ?string $permission): array
    {
        return compact('label', 'icon', 'route', 'active', 'permission') + ['status' => 'live'];
    }

    private function soon(string $label, string $icon): array
    {
        return ['label' => $label, 'icon' => $icon, 'route' => null, 'active' => '', 'permission' => null, 'status' => 'soon'];
    }
}
