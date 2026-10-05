<?php

return [
    'title' => 'Rate Plans',
    'description' => 'Create and manage rate plans. Define pricing, meal plans, cancellation policy and restrictions.',
    'add_rate_plan' => 'Add Rate Plan',
    'edit_rate_plan' => 'Edit Rate Plan',
    'rate_plan' => 'Rate Plan',
    'rate_plan_singular' => 'rate plan',
    'rate_plan_plural' => 'rate plans',
    'details_title' => 'Rate Plan Details',
    'search' => 'Search rate plan by name or code…',
    'all_room_types' => 'All Room Types',
    'all_meal_plans' => 'All Meal Plans',
    'all_statuses' => 'All Statuses',
    'copy_suffix' => '(Copy)',
    'default_badge' => 'Default',
    'none_linked' => 'Not linked',
    'select_plan' => 'Select a rate plan to see its details.',
    'no_rate_plans' => 'No rate plans yet',
    'no_rate_plans_hint' => 'Create a rate plan, then link it to your room types.',

    'kpis' => ['all' => 'All Rate Plans', 'active' => 'Active', 'inactive' => 'Inactive', 'mapped' => 'Channel Mapped', 'unmapped' => 'Not Mapped'],

    'columns' => [
        'name' => 'Rate Plan Name', 'code' => 'Code', 'meal_plan' => 'Meal Plan', 'room_types' => 'Room Types', 'policy' => 'Policy',
        'base_rate' => 'Base Rate (:currency)', 'min_los' => 'Min LOS', 'channels' => 'Channels', 'status' => 'Status', 'actions' => 'Actions',
    ],

    'panel_tabs' => ['overview' => 'Overview', 'rates' => 'Rates & Restrictions', 'room_types' => 'Room Types', 'channels' => 'Channels'],

    'channels' => [
        'pms' => 'Front desk (PMS)', 'booking_engine' => 'Booking engine', 'channels' => 'OTA channels',
        'mapped' => ':count product(s) mapped to channels', 'not_mapped' => 'Not mapped to any channel yet',
        'mapping_later' => 'Channel mapping is managed in the Channel Manager.',
    ],

    'quick' => [
        'set_rates' => 'Set Rates', 'copy' => 'Copy', 'deactivate' => 'Deactivate', 'activate' => 'Activate',
        'map_channels' => 'Map to Channels', 'view_history' => 'View History',
    ],

    'fields' => [
        'name' => 'Rate Plan Name', 'code' => 'Code', 'description' => 'Description (shown to guests)', 'meal_plan' => 'Meal Plan',
        'policy' => 'Cancellation Policy', 'payment_type' => 'Payment', 'deposit_value' => 'Deposit',
        'min_los' => 'Min Length of Stay', 'max_los' => 'Max Length of Stay', 'min_advance' => 'Book at least (days before arrival)',
        'max_advance' => 'Book at most (days before arrival)', 'booking_window' => 'Booking Window', 'sell_on' => 'Sell On',
        'status' => 'Status', 'is_default' => 'Default rate plan of the property', 'price' => 'Price per night',
        'base_rate' => 'Base Rate', 'applicable_to' => 'Applicable To', 'pricing' => 'Pricing',
        'parent' => 'Derived From', 'adjust_type' => 'Adjustment Type', 'adjust_value' => 'Adjustment',
        'single' => 'Single occupancy', 'extra_adult' => 'Extra adult', 'extra_child' => 'Extra child',
        'policy_code' => 'Policy Code', 'policy_name' => 'Policy Name', 'refundable' => 'Refundable',
        'hours_before' => 'Hours before arrival', 'charge' => 'Charge', 'charge_value' => 'Value', 'applies_to' => 'Applies to',
    ],

    'sections' => [
        'basic' => 'Rate Plan Details',
        'basic_desc' => 'A rate plan is a set of commercial terms. Link it to room types below to sell it.',
        'policy' => 'Meal Plan, Cancellation & Payment',
        'restrictions' => 'Stay Rules & Booking Window',
        'distribution' => 'Distribution',
        'room_types' => 'Room Types & Prices',
        'room_types_desc' => 'Each linked room type becomes a product. Price it manually or derive it from another rate plan.',
        'occupancy' => 'Occupancy Pricing',
        'occupancy_desc' => 'Per night, relative to the base occupancy price. Use a negative amount for discounts.',
    ],

    'pricing' => [
        'manual' => 'Manual prices', 'derived' => 'Derived from another rate plan',
        'manual_hint' => 'Enter a price per night for each room type.',
        'derived_hint' => 'Prices follow the parent rate plan of the same room type, e.g. −10 %.',
        'derive_all' => 'Derive all linked room types from',
        'apply_all' => 'Apply to All',
    ],
    'adjust_types' => ['percent' => 'Percent (%)', 'fixed' => 'Fixed amount', 'fixed_per_person' => 'Fixed per person'],
    'occupancy_types' => ['fixed' => 'Amount', 'percent' => '%'],
    'age_bands' => ['infant' => 'Infant (:min–:max)', 'child' => 'Child (:min–:max)', 'teen' => 'Teen (:min–:max)'],

    'payment_types' => [
        'pay_at_property' => 'Pay at property', 'prepay_full' => 'Full prepayment',
        'deposit_percent' => 'Deposit (% of stay)', 'deposit_nights' => 'Deposit (nights)',
    ],

    'meal_plans' => [
        'RO' => 'Room Only', 'BB' => 'Breakfast', 'HB' => 'Half Board', 'FB' => 'Full Board', 'AI' => 'All Inclusive',
        'LO' => 'Lunch Only', 'DI' => 'Dinner Only', 'BD' => 'Breakfast + Dinner',
    ],

    'policy' => [
        'refundable' => 'Flexible', 'non_refundable' => 'Non-Refundable', 'new' => 'New Policy', 'edit' => 'Edit Policy',
        'rules' => 'Charges', 'add_rule' => 'Add Charge', 'cancellation' => 'Cancellation', 'no_show' => 'No-show',
        'charge_types' => [
            'none' => 'Free', 'first_night' => 'First night', 'nights' => 'Number of nights', 'percent' => 'Percent of stay',
            'fixed' => 'Fixed amount', 'full' => 'Full stay',
        ],
        'rule_text' => ':charge if cancelled less than :hours h before arrival',
        'no_show_text' => 'No-show: :charge',
    ],

    'one_night' => '1 night',
    'n_nights' => ':count nights',
    'nights_suffix' => 'nights',
    'current_price' => 'Current price: :price',
    'daily_rates_hint' => 'Daily prices and restrictions are set on the Calendar.',
    'no_room_types' => 'No room types yet. Create a room type first.',
    'occupancy' => [
        'adult' => 'Adults', 'child' => 'Child', 'infant' => 'Infant', 'guest' => 'Guest type', 'count' => 'Guest number',
        'count_hint' => 'Adults: total adults in the room (1 = single occupancy). Children: the n-th child.',
        'age_band' => 'Age band', 'add' => 'Add Rule', 'none' => 'No occupancy rules: the price is the same for any number of guests.',
    ],
    'days_range' => ':from – :to days',
    'any' => 'Any',

    'defaults' => [
        'bar_name' => 'Standard Rate – Room Only',
        'bar_text' => 'Best available rate without meals.',
        'flexible_name' => 'Flexible – free cancellation until 24 hours before arrival',
        'flexible_text' => 'Free cancellation until 24 hours before arrival. Later cancellations and no-shows are charged the first night.',
        'non_refundable_name' => 'Non-refundable',
        'non_refundable_text' => 'The full stay is charged at booking and is not refunded on cancellation or no-show.',
    ],

    'messages' => [
        'created' => 'Rate plan created.',
        'updated' => 'Rate plan saved.',
        'activated' => 'Rate plan activated.',
        'deactivated' => 'Rate plan deactivated.',
        'copied' => 'Copy created as :code (inactive). Review it, then activate it.',
        'policy_saved' => 'Cancellation policy saved.',
    ],

    'errors' => [
        'adjust_percent_range' => 'A percent adjustment must be greater than −100.',
        'adjust_required' => 'Enter the adjustment type and value.',
        'age_band_unknown' => 'Unknown age band.',
        'has_active_children' => ':count active product(s) are derived from this one. Change them first.',
        'non_refundable_free' => 'A non-refundable policy cannot have a free cancellation window.',
        'occupancy_count' => 'Guest count must be between 1 and :max.',
        'occupancy_duplicate' => 'This occupancy rule is entered twice.',
        'parent_cycle' => 'This would make the price depend on itself.',
        'parent_inactive' => 'The parent rate plan link is inactive.',
        'parent_missing' => 'Choose the rate plan this price is derived from.',
        'parent_not_linked' => ':plan is not linked to :room_type. Link it first or use a manual price.',
        'parent_other_property' => 'The parent rate plan belongs to another property.',
        'parent_self' => 'A rate plan cannot be derived from itself.',
        'parent_too_deep' => 'Derived prices can be chained at most :max levels deep.',
        'price_required' => 'Enter a price of 0 or more.',
        'product_inactive' => 'This rate plan link is inactive.',
        'rule_duplicate_window' => 'Two charges use the same time window.',
        'rule_percent_max' => 'A percentage cannot exceed 100.',
        'rule_value_required' => 'Enter a value for this charge.',
        'max_los_below_min' => 'Max length of stay cannot be shorter than the minimum.',
        'window_order' => 'The latest booking day must be after the earliest one.',
        'deposit_value' => 'Enter a deposit greater than 0 (at most 100 for a percentage).',
        'default_cannot_deactivate' => 'The default rate plan cannot be deactivated. Make another plan the default first.',
        'default_inactive' => 'An inactive rate plan cannot be the default.',
        'meal_plan_required' => 'Choose a meal plan.',
        'policy_required' => 'Choose a cancellation policy.',
    ],
];
