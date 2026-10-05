<?php

/*
| Permission catalogue and system roles.
| The PermissionSeeder syncs this file into the database, so adding a permission
| or changing a default role here and re-running the seeder is all that is needed.
*/

return [

    'platform' => [
        'platform' => [
            'platform.dashboard',
            'platform.properties.manage',
            'platform.users.manage',
            'platform.plans.manage',
            'platform.subscriptions.manage',
            'platform.audit.view',
            'platform.system.view',
        ],
    ],

    'property' => [
        'property'     => ['property.view', 'property.update'],
        'rooms'        => ['rooms.view', 'rooms.create', 'rooms.update'],
        'rate_plans'   => ['rate_plans.view', 'rate_plans.create', 'rate_plans.update'],
        'calendar'     => ['calendar.view', 'calendar.update'],
        'reservations' => ['reservations.view', 'reservations.create', 'reservations.update', 'reservations.cancel'],
        'front_desk'   => ['checkin.perform', 'checkout.perform', 'housekeeping.update'],
        'guests'       => ['guests.view', 'guests.update'],
        'billing'      => ['folio.view', 'folio.post', 'payments.manage', 'invoices.manage'],
        'offers'       => ['offers.manage'],
        'taxes'        => ['taxes.manage'],
        'channels'     => ['channels.manage'],
        'reports'      => ['reports.view'],
        'users'        => ['users.manage'],
    ],

    // Roles copied to every installation. "*" = every permission of that scope.
    'roles' => [
        'platform' => [
            'super_admin' => ['name' => 'Super Admin', 'color' => 'violet', 'permissions' => ['*']],
            'it_support'  => ['name' => 'IT Support',  'color' => 'sky', 'permissions' => ['platform.dashboard', 'platform.audit.view', 'platform.system.view']],
        ],
        'property' => [
            'owner' => ['name' => 'Owner', 'color' => 'violet', 'permissions' => ['*']],
            'hotel_manager' => ['name' => 'Hotel Manager', 'color' => 'blue', 'permissions' => ['*']],
            'front_desk' => ['name' => 'Front Desk', 'color' => 'sky', 'permissions' => [
                'property.view', 'rooms.view', 'rate_plans.view', 'calendar.view', 'reservations.view',
                'reservations.create', 'reservations.update', 'checkin.perform', 'checkout.perform',
                'guests.view', 'guests.update', 'folio.view', 'folio.post', 'payments.manage',
            ]],
            'reservations' => ['name' => 'Reservations', 'color' => 'amber', 'permissions' => [
                'property.view', 'rooms.view', 'rate_plans.view', 'calendar.view', 'reservations.view',
                'reservations.create', 'reservations.update', 'reservations.cancel', 'guests.view', 'guests.update',
            ]],
            'housekeeping' => ['name' => 'Housekeeping', 'color' => 'green', 'permissions' => [
                'property.view', 'rooms.view', 'housekeeping.update',
            ]],
            'accounts' => ['name' => 'Accounts', 'color' => 'rose', 'permissions' => [
                'property.view', 'reservations.view', 'guests.view', 'folio.view', 'folio.post',
                'payments.manage', 'invoices.manage', 'taxes.manage', 'reports.view',
            ]],
            'revenue_manager' => ['name' => 'Revenue Manager', 'color' => 'purple', 'permissions' => [
                'property.view', 'rooms.view', 'rate_plans.view', 'rate_plans.create', 'rate_plans.update',
                'calendar.view', 'calendar.update', 'offers.manage', 'channels.manage', 'reports.view', 'reservations.view',
            ]],
            'guest_relations' => ['name' => 'Guest Relations', 'color' => 'teal', 'permissions' => [
                'property.view', 'reservations.view', 'guests.view', 'guests.update',
            ]],
            'sales_marketing' => ['name' => 'Sales & Marketing', 'color' => 'pink', 'permissions' => [
                'property.view', 'rate_plans.view', 'offers.manage', 'reports.view', 'reservations.view',
            ]],
        ],
    ],
];
