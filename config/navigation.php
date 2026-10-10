<?php

/*
| Sidebar navigation. Labels are translation keys, icons are Lucide icon names,
| "permission" hides the item from users who do not hold it.
| Items whose route does not exist yet are shown as disabled ("Soon").
*/

return [

    'property' => [
        ['key' => 'dashboard',   'label' => 'nav.dashboard',   'icon' => 'house',          'route' => 'property.dashboard', 'permission' => 'property.view'],
        ['key' => 'reservations','label' => 'nav.reservations','icon' => 'calendar-check', 'route' => 'property.reservations', 'permission' => 'reservations.view'],
        ['key' => 'front_desk',  'label' => 'nav.front_desk',  'icon' => 'concierge-bell', 'route' => 'property.front-desk', 'permission' => 'reservations.view'],
        ['key' => 'calendar',    'label' => 'nav.calendar',    'icon' => 'calendar-days',  'route' => 'property.calendar', 'permission' => 'calendar.view'],
        ['key' => 'properties',  'label' => 'nav.properties',  'icon' => 'hotel',          'route' => 'property.properties', 'permission' => 'property.view'],
        ['key' => 'room_types',  'label' => 'nav.room_types',  'icon' => 'bed-double',     'route' => 'property.room-types', 'permission' => 'rooms.view'],
        ['key' => 'rooms',       'label' => 'nav.rooms',       'icon' => 'door-open',      'route' => 'property.rooms', 'permission' => 'rooms.view'],
        ['key' => 'housekeeping','label' => 'nav.housekeeping','icon' => 'spray-can',    'route' => 'property.housekeeping', 'permission' => 'rooms.view'],
        ['key' => 'rate_plans',  'label' => 'nav.rate_plans',  'icon' => 'tags',           'route' => 'property.rate-plans', 'permission' => 'rate_plans.view'],
        ['key' => 'guests',      'label' => 'nav.guests',      'icon' => 'users',          'route' => 'property.guests', 'permission' => 'guests.view'],
        ['key' => 'offers',      'label' => 'nav.offers',      'icon' => 'badge-percent',  'route' => 'property.offers', 'permission' => 'offers.manage'],
        ['key' => 'taxes',       'label' => 'nav.taxes',       'icon' => 'receipt',        'route' => 'property.taxes', 'permission' => 'taxes.manage'],
        ['key' => 'services',    'label' => 'nav.services',    'icon' => 'concierge-bell', 'route' => 'property.services', 'permission' => 'services.manage'],
        ['key' => 'reports',     'label' => 'nav.reports',     'icon' => 'chart-column',   'route' => 'property.reports', 'permission' => 'reports.view'],
        ['key' => 'channels',    'label' => 'nav.channels',    'icon' => 'network',        'route' => 'property.channels', 'permission' => 'channels.manage'],
        ['key' => 'users',       'label' => 'nav.users_roles', 'icon' => 'user-cog',       'route' => 'property.users', 'permission' => 'users.manage'],
        ['key' => 'settings',    'label' => 'nav.settings',    'icon' => 'settings',       'route' => 'property.settings', 'permission' => 'property.update'],
    ],

    'platform' => [
        ['key' => 'admin_dashboard',  'label' => 'nav.dashboard',     'icon' => 'house',     'route' => 'admin.dashboard',  'permission' => 'platform.dashboard'],
        ['key' => 'admin_properties', 'label' => 'nav.properties',    'icon' => 'hotel',     'route' => 'admin.properties', 'permission' => 'platform.properties.manage'],
        ['key' => 'admin_users',      'label' => 'nav.users_roles',   'icon' => 'user-cog',  'route' => 'admin.users',      'permission' => 'platform.users.manage'],
        ['key' => 'admin_approvals',  'label' => 'nav.approvals',     'icon' => 'check-circle','route' => 'admin.approvals', 'permission' => 'platform.approvals.manage'],
        ['key' => 'admin_plans',      'label' => 'nav.subscriptions', 'icon' => 'credit-card','route' => 'admin.plans',     'permission' => 'platform.plans.manage'],
        ['key' => 'admin_audit',      'label' => 'nav.audit',         'icon' => 'scroll-text','route' => 'admin.audit',     'permission' => 'platform.audit.view'],
        ['key' => 'admin_email',      'label' => 'nav.email',         'icon' => 'mail',      'route' => 'admin.email',      'permission' => 'platform.settings.manage'],
        ['key' => 'admin_system',     'label' => 'nav.system',        'icon' => 'activity',  'route' => 'admin.system',     'permission' => 'platform.system.view'],
    ],
];
