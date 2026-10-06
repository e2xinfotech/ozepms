<?php

return [
    'title' => 'Amenities',
    'description' => 'Standard amenities and the custom ones your property adds. Select them on each room type.',
    'add' => 'Add Amenity',
    'edit' => 'Edit Amenity',
    'search' => 'Search amenity…',
    'all_categories' => 'All Categories',
    'item_plural' => 'amenities',
    'global' => 'Standard',
    'custom' => 'Custom',
    'used_by' => '{0} Not used|{1} :count room type|[2,*] :count room types',
    'read_only' => 'Standard amenities are maintained by E2X and cannot be edited.',

    'tabs' => ['all' => 'All', 'global' => 'Standard', 'custom' => 'Custom'],
    'columns' => ['name' => 'Amenity', 'category' => 'Category', 'source' => 'Source', 'used' => 'Used By', 'status' => 'Status', 'actions' => 'Actions', 'facility' => 'Property Facility'],
    'fields' => ['name' => 'Name', 'category' => 'Category', 'icon' => 'Icon', 'status' => 'Status'],

    'categories' => [
        'room' => 'Room', 'bathroom' => 'Bathroom', 'media' => 'Media & Technology', 'kitchen' => 'Food & Kitchen',
        'property' => 'Property', 'service' => 'Services', 'other' => 'Other',
    ],

    'items' => [
        'wifi' => 'Free Wi-Fi', 'air_conditioning' => 'Air conditioning', 'heating' => 'Heating', 'ceiling_fan' => 'Ceiling fan',
        'desk' => 'Work desk', 'wardrobe' => 'Wardrobe', 'seating_area' => 'Seating area', 'balcony' => 'Balcony',
        'sea_view' => 'Sea view', 'mountain_view' => 'Mountain view', 'safe' => 'In-room safe', 'telephone' => 'Telephone',
        'iron' => 'Iron and ironing board', 'soundproofing' => 'Soundproofing', 'blackout_curtains' => 'Blackout curtains',
        'baby_cot' => 'Baby cot on request', 'private_bathroom' => 'Private bathroom', 'bathtub' => 'Bathtub', 'shower' => 'Shower',
        'hair_dryer' => 'Hair dryer', 'toiletries' => 'Free toiletries', 'bathrobe' => 'Bathrobe', 'tv' => 'TV',
        'smart_tv' => 'Smart TV', 'satellite_channels' => 'Satellite channels', 'refrigerator' => 'Refrigerator', 'minibar' => 'Minibar',
        'coffee_maker' => 'Coffee machine', 'kettle' => 'Electric kettle', 'microwave' => 'Microwave', 'kitchenette' => 'Kitchenette',
        'dining_area' => 'Dining area', 'washing_machine' => 'Washing machine', 'parking' => 'Parking', 'swimming_pool' => 'Swimming pool',
        'fitness_center' => 'Fitness centre', 'restaurant' => 'Restaurant', 'bar' => 'Bar', 'spa' => 'Spa',
        'wheelchair_accessible' => 'Wheelchair accessible', 'non_smoking' => 'Non-smoking', 'room_service' => 'Room service',
        'daily_housekeeping' => 'Daily housekeeping', 'laundry' => 'Laundry service', 'airport_shuttle' => 'Airport shuttle',
        'front_desk_24h' => '24-hour front desk',
    ],

    'messages' => ['created' => 'Amenity added.', 'updated' => 'Amenity saved.', 'facility_saved' => 'Property facilities saved.'],

    'facility_hint' => "Tick the facilities the whole property offers; they are shown on the property profile. Room amenities are chosen on each room type.",
    'errors' => [
        'not_a_facility' => "Only property-wide amenities (pool, parking, spa …) can be property facilities.",
        'duplicate' => 'An amenity called ":name" already exists.',
        'global_read_only' => 'Standard amenities cannot be changed.',
    ],
    'add_missing' => 'Add Missing Amenity',
    'add_hint' => 'Saved to the central amenity list and available on every room type and room.',
    'room_only' => 'This room only',
    'from_room_type' => 'Included with the room type',
];
