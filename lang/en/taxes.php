<?php

return [
    'title' => 'Taxes & Fees',
    'description' => 'Create and manage taxes, service charges and other fees. Apply them to room rates, add-ons or invoices.',
    'add' => 'Add Tax / Fee',
    'new' => 'New Tax / Fee',
    'settings' => 'Tax Settings',
    'search' => 'Search tax or fee…',
    'all_statuses' => 'All Statuses',
    'item_plural' => 'items',
    'select_rule' => 'Select a tax or fee to edit it.',
    'no_rules' => 'No taxes or fees yet',
    'no_rules_hint' => 'Add your taxes and fees, or copy the standard ones for your country.',
    'templates_banner' => 'Standard tax rules for your country are available (for India: GST slabs 0 % up to ₹1,000, 5 % up to ₹7,500, 18 % above, per room-night).',
    'copy_templates' => 'Copy standard rules',

    'tabs' => ['all' => 'All', 'tax' => 'Taxes', 'service_charge' => 'Service Charges', 'fee' => 'Fees'],
    'panel_tabs' => ['details' => 'Details', 'applies' => 'Applies To', 'advanced' => 'Advanced'],

    'columns' => [
        'name' => 'Name', 'code' => 'Code', 'type' => 'Type', 'rate' => 'Rate / Amount', 'apply_to' => 'Apply To',
        'calculation' => 'Calculation', 'status' => 'Status', 'default' => 'Default', 'actions' => 'Actions',
    ],

    'kinds' => ['tax' => 'Tax', 'service_charge' => 'Service Charge', 'fee' => 'Fee'],
    'tax_types' => [
        'gst' => 'GST', 'vat' => 'VAT', 'sales' => 'Sales tax', 'tourism' => 'Tourism tax', 'city' => 'City tax',
        'local' => 'Local tax', 'service_charge' => 'Service charge', 'other' => 'Other',
    ],
    'apply_to' => ['room_charges' => 'Room Charges', 'add_ons' => 'Add-ons', 'fnb' => 'F&B', 'events' => 'Events'],
    'methods' => ['percent' => 'Percentage', 'fixed' => 'Fixed Amount'],
    'bases' => ['per_room_night' => 'Per Room Per Night', 'per_person_night' => 'Per Person Per Night', 'per_stay' => 'Per Stay', 'per_booking' => 'Per Booking'],
    'basis_short' => ['per_room_night' => 'per room per night', 'per_person_night' => 'per person per night', 'per_stay' => 'per stay', 'per_booking' => 'per booking'],
    'component_modes' => ['single' => 'Single tax', 'gst_split' => 'GST: CGST + SGST (IGST inter-state)'],

    'fields' => [
        'name' => 'Name', 'code' => 'Code', 'type' => 'Type', 'rate' => 'Rate / Amount', 'basis' => 'Basis', 'apply_to' => 'Apply To',
        'method' => 'Calculation Method', 'description' => 'Description', 'options' => 'Options', 'status' => 'Status',
        'default_new' => 'Set as default tax/fee for new room types', 'include_displayed' => 'Include in rate displayed to guest',
        'tax_type' => 'Tax Category', 'component_mode' => 'Components', 'slab' => 'Tariff slab (per room-night)',
        'slab_min' => 'From', 'slab_max' => 'To', 'inclusive' => 'Prices already include this tax', 'compound' => 'Compound (applied on price + earlier taxes)',
        'priority' => 'Calculation Order', 'effective_from' => 'Effective From', 'effective_to' => 'Effective To',
        'room_types' => 'Room Types', 'rate_plans' => 'Rate Plans', 'sac' => 'SAC / HSN',
    ],
    'hints' => [
        'slab' => 'Leave empty to apply at any tariff. Bounds are inclusive.',
        'applies' => 'Leave both lists empty to apply to every room type and rate plan.',
        'priority' => 'Lower numbers are calculated first.',
    ],

    'slab_range' => ':min – :max',
    'slab_up_to' => 'up to :max',
    'slab_above' => 'above :min',
    'per_room_night_slab' => 'tariff per room-night',

    'calculator' => [
        'title' => 'Tax Calculator',
        'intro' => 'Check which taxes apply to a room charge with the current rules.',
        'tariff' => 'Tariff per room-night', 'nights' => 'Nights', 'persons' => 'Guests', 'guest_state' => 'Guest state code',
        'calculate' => 'Calculate', 'taxable' => 'Taxable value', 'tax_total' => 'Total tax', 'total' => 'Total',
        'component' => 'Component', 'rate' => 'Rate', 'amount' => 'Amount', 'none' => 'No tax applies.',
    ],

    'delete_confirm' => 'Delete :name? This cannot be undone.',

    'messages' => [
        'created' => 'Tax / fee created.',
        'updated' => 'Tax / fee saved.',
        'activated' => 'Tax / fee activated.',
        'deactivated' => 'Tax / fee deactivated.',
        'deleted' => 'Tax / fee deleted.',
        'templates_copied' => '{0} No rules copied: they already exist.|{1} :count rule copied.|[2,*] :count rules copied.',
    ],

    'errors' => [
        'in_use' => 'This rule was used on guest charges and cannot be deleted. Deactivate it instead.',
        'apply_to_required' => 'Choose at least one charge type.',
        'rate_invalid' => 'Enter a rate of 0 or more.',
        'percent_max' => 'A percentage cannot exceed 100.',
        'gst_split_tax_only' => 'Only taxes can be split into CGST and SGST.',
        'slab_order' => 'The upper bound must be at least the lower bound.',
        'dates_order' => 'The end date must be on or after the start date.',
        'inclusive_percent_only' => 'Only percentage taxes can be included in prices.',
        'code_taken' => 'The code :code is already used.',
    ],
];
