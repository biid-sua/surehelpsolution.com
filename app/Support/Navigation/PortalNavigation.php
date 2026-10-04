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
                $this->item('Notifications', 'bell', 'app.settings.notifications', 'app.settings.*', null),
            ],
            'admin' => [
                $this->item('Overview', 'home', 'admin.home', 'admin.home', 'dashboard.view'),
                $this->item('Organizations', 'building', 'admin.organizations.index', 'admin.organizations.*', 'organization.view'),
                $this->item('Call review', 'inbox', 'admin.calls.review', 'admin.calls.review', 'calls.update'),
                $this->item('Escalations', 'alert', 'admin.escalations', 'admin.escalations', 'escalations.view'),
                $this->item('Audit log', 'shield', 'admin.audit', 'admin.audit', 'audit_logs.view'),
                $this->classic('Dashboard & new users', 'chart', 'admin.dashboard', 'users.create'),
                $this->classic('Users', 'user', 'admin.users.index', 'users.view'),
                $this->classic('Duty schedules', 'calendar', 'duty-schedules.index', 'users.view'),
                $this->classic('Contact forms', 'chat', 'admin.contact-submissions.index', 'dashboard.view'),
                $this->item('Agent workspace', 'phone', 'agent.home', 'agent.*', 'calls.create'),
            ],
            'agent' => [
                $this->item('Businesses', 'building', 'agent.home', 'agent.*', 'calls.create'),
                $this->classic('Classic call form', 'phone', 'admin.agent-dashboard', 'calls.create'),
                $this->classic('My duty schedule', 'calendar', 'duty-schedules.index', null),
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
            $item['current'] = $item['route'] !== null && request()->routeIs($item['active']);
            $items[] = $item;
        }

        return $items;
    }

    private function item(string $label, string $icon, string $route, string $active, ?string $permission): array
    {
        return compact('label', 'icon', 'route', 'active', 'permission') + ['status' => 'live'];
    }

    /** A screen from the previous admin console, linked until it is rebuilt in this shell. */
    private function classic(string $label, string $icon, string $route, ?string $permission): array
    {
        return ['label' => $label, 'icon' => $icon, 'route' => $route, 'active' => $route, 'permission' => $permission, 'status' => 'classic'];
    }

    private function soon(string $label, string $icon): array
    {
        return ['label' => $label, 'icon' => $icon, 'route' => null, 'active' => '', 'permission' => null, 'status' => 'soon'];
    }
}
