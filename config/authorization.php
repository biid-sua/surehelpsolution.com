<?php

/*
|--------------------------------------------------------------------------
| Permission catalogue (spec §5, docs/decisions.md D9, docs/permissions.md)
|--------------------------------------------------------------------------
|
| Single source of truth. `php artisan permissions:sync` (also run by a
| migration) writes the global roles to the database. Organization roles are
| evaluated from this file directly.
|
| Code must check permissions, never role names.
|
*/

$all = [
    'dashboard.view',
    'organization.view', 'organization.update',
    'users.view', 'users.create', 'users.update', 'users.delete',
    'customers.view', 'customers.create', 'customers.update', 'customers.delete',
    'calls.view', 'calls.create', 'calls.update', 'calls.delete', 'calls.recording.view',
    'appointments.view', 'appointments.create', 'appointments.update', 'appointments.cancel',
    'calendar.view', 'calendar.manage',
    'messages.view', 'messages.send',
    'tasks.view', 'tasks.create', 'tasks.update',
    'knowledge_base.view', 'knowledge_base.manage',
    'ai.view', 'ai.manage',
    'integrations.view', 'integrations.manage',
    'reports.view',
    'billing.view', 'billing.manage',
    'subscriptions.view', 'subscriptions.manage',
    'marketing.view', 'marketing.manage',
    'seo.view', 'seo.manage',
    'reviews.view', 'reviews.manage',
    'social.view', 'social.manage',
    'settings.view', 'settings.manage',
];

$except = fn (array $excluded) => array_values(array_diff($all, $excluded));

$agentWork = [
    'dashboard.view', 'organization.view',
    'customers.view', 'customers.create', 'customers.update',
    'calls.view', 'calls.create', 'calls.update',
    'appointments.view', 'appointments.create', 'appointments.update', 'appointments.cancel',
    'calendar.view',
    'messages.view', 'messages.send',
    'tasks.view', 'tasks.create', 'tasks.update',
    'knowledge_base.view',
];

return [

    'permissions' => $all,

    /*
    | Global roles (stored with spatie/laravel-permission).
    | scope "platform": applies to every organization.
    | scope "assigned": applies only to organizations the user is assigned to (agent_assignments).
    */
    'roles' => [
        'super_admin' => [
            'label' => 'Super Admin',
            'scope' => 'platform',
            'permissions' => $all,
        ],
        'operations_manager' => [
            'label' => 'Operations Manager',
            'scope' => 'platform',
            'permissions' => $except([
                'users.delete', 'customers.delete', 'calls.delete',
                'billing.manage', 'subscriptions.manage', 'settings.manage',
                'ai.manage', 'integrations.manage',
            ]),
        ],
        'support_agent' => [
            'label' => 'Support Agent',
            'scope' => 'platform',
            'permissions' => [
                'dashboard.view', 'organization.view', 'users.view',
                'customers.view', 'calls.view', 'appointments.view', 'calendar.view',
                'messages.view', 'messages.send', 'tasks.view', 'tasks.create', 'tasks.update',
                'knowledge_base.view', 'integrations.view', 'billing.view', 'subscriptions.view',
                'reports.view', 'settings.view',
            ],
        ],
        'agent_supervisor' => [
            'label' => 'Agent Supervisor',
            'scope' => 'assigned',
            'permissions' => array_merge($agentWork, ['calls.recording.view', 'knowledge_base.manage', 'reports.view']),
        ],
        'agent' => [
            'label' => 'Agent',
            'scope' => 'assigned',
            'permissions' => $agentWork,
        ],
    ],

    /*
    | Roles inside one client organization (organization_user.role).
    */
    'organization_roles' => [
        'owner' => [
            'label' => 'Business Owner',
            'permissions' => $all,
        ],
        'manager' => [
            'label' => 'Business Manager',
            'permissions' => $except(['billing.manage', 'subscriptions.manage', 'users.delete', 'organization.update']),
        ],
        'staff' => [
            'label' => 'Staff',
            'permissions' => [
                'dashboard.view',
                'customers.view', 'customers.create', 'customers.update',
                'calls.view',
                'appointments.view', 'appointments.create', 'appointments.update',
                'calendar.view',
                'messages.view', 'messages.send',
                'tasks.view', 'tasks.create', 'tasks.update',
                'knowledge_base.view',
                'reports.view',
            ],
        ],
    ],

    /*
    | Default global role for each legacy portal type (users.role).
    */
    'portal_defaults' => [
        'admin' => 'super_admin',
        'agent' => 'agent',
    ],

];
