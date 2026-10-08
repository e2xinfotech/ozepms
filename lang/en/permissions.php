<?php

return [
    'modules' => [
        'platform' => 'Platform', 'property' => 'Property', 'rooms' => 'Rooms', 'rate_plans' => 'Rate Plans', 'calendar' => 'Calendar',
        'reservations' => 'Reservations', 'front_desk' => 'Front Desk', 'guests' => 'Guests', 'billing' => 'Billing',
        'offers' => 'Offers', 'taxes' => 'Taxes', 'channels' => 'Channels', 'reports' => 'Reports', 'users' => 'Users',
    ],
    'keys' => [
        'platform.dashboard' => 'View platform dashboard',
        'platform.properties.manage' => 'Manage all properties',
        'platform.users.manage' => 'Manage platform users',
        'platform.plans.manage' => 'Manage subscription plans',
        'platform.subscriptions.manage' => 'Manage subscriptions',
        'platform.audit.view' => 'View audit log',
        'platform.system.view' => 'View system health',
        'property.view' => 'View property',
        'property.update' => 'Edit property settings',
        'rooms.view' => 'View rooms', 'rooms.create' => 'Create rooms', 'rooms.update' => 'Edit rooms',
        'rate_plans.view' => 'View rate plans', 'rate_plans.create' => 'Create rate plans', 'rate_plans.update' => 'Edit rate plans',
        'calendar.view' => 'View calendar', 'calendar.update' => 'Change rates & availability',
        'reservations.view' => 'View reservations', 'reservations.create' => 'Create reservations',
        'reservations.update' => 'Modify reservations', 'reservations.cancel' => 'Cancel reservations',
        'checkin.perform' => 'Check guests in', 'checkout.perform' => 'Check guests out', 'checkout.override_balance' => 'Check out with an open balance', 'housekeeping.update' => 'Update housekeeping', 'housekeeping.manage' => 'Manage housekeeping staff & room assignments',
        'guests.view' => 'View guests', 'guests.update' => 'Edit guests',
        'folio.view' => 'View folios', 'folio.post' => 'Post charges', 'payments.manage' => 'Record payments & refunds',
        'invoices.manage' => 'Issue invoices', 'services.manage' => 'Manage services & extras', 'billing.override' => 'Override billing checks (void room charges, open balance)',
        'offers.manage' => 'Manage offers', 'taxes.manage' => 'Manage taxes & fees', 'channels.manage' => 'Manage channels',
        'reports.view' => 'View reports', 'users.manage' => 'Manage users & roles',
    ],
];
