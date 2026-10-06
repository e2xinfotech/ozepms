<?php

return [
    'title' => 'Ausstattung',
    'description' => 'Standardausstattung und die eigene Ausstattung Ihres Hauses. Wählen Sie sie bei jedem Zimmertyp aus.',
    'add' => 'Ausstattung hinzufügen',
    'edit' => 'Ausstattung bearbeiten',
    'search' => 'Ausstattung suchen…',
    'all_categories' => 'Alle Kategorien',
    'item_plural' => 'Ausstattungsmerkmale',
    'global' => 'Standard',
    'custom' => 'Eigene',
    'used_by' => '{0} Nicht verwendet|{1} :count Zimmertyp|[2,*] :count Zimmertypen',
    'read_only' => 'Standardausstattung wird von E2X gepflegt und kann nicht bearbeitet werden.',

    'tabs' => ['all' => 'Alle', 'global' => 'Standard', 'custom' => 'Eigene'],
    'columns' => ['name' => 'Ausstattung', 'category' => 'Kategorie', 'source' => 'Herkunft', 'used' => 'Verwendet bei', 'status' => 'Status', 'actions' => 'Aktionen', 'facility' => 'Einrichtung der Unterkunft'],
    'fields' => ['name' => 'Name', 'category' => 'Kategorie', 'icon' => 'Symbol', 'status' => 'Status'],

    'categories' => [
        'room' => 'Zimmer', 'bathroom' => 'Badezimmer', 'media' => 'Medien & Technik', 'kitchen' => 'Essen & Küche',
        'property' => 'Haus', 'service' => 'Services', 'other' => 'Sonstiges',
    ],

    'items' => [
        'wifi' => 'Kostenloses WLAN', 'air_conditioning' => 'Klimaanlage', 'heating' => 'Heizung', 'ceiling_fan' => 'Deckenventilator',
        'desk' => 'Schreibtisch', 'wardrobe' => 'Kleiderschrank', 'seating_area' => 'Sitzecke', 'balcony' => 'Balkon',
        'sea_view' => 'Meerblick', 'mountain_view' => 'Bergblick', 'safe' => 'Zimmersafe', 'telephone' => 'Telefon',
        'iron' => 'Bügeleisen und -brett', 'soundproofing' => 'Schallisolierung', 'blackout_curtains' => 'Verdunkelungsvorhänge',
        'baby_cot' => 'Babybett auf Anfrage', 'private_bathroom' => 'Eigenes Bad', 'bathtub' => 'Badewanne', 'shower' => 'Dusche',
        'hair_dryer' => 'Haartrockner', 'toiletries' => 'Kostenlose Pflegeprodukte', 'bathrobe' => 'Bademantel', 'tv' => 'TV',
        'smart_tv' => 'Smart-TV', 'satellite_channels' => 'Satellitenprogramme', 'refrigerator' => 'Kühlschrank', 'minibar' => 'Minibar',
        'coffee_maker' => 'Kaffeemaschine', 'kettle' => 'Wasserkocher', 'microwave' => 'Mikrowelle', 'kitchenette' => 'Kochnische',
        'dining_area' => 'Essbereich', 'washing_machine' => 'Waschmaschine', 'parking' => 'Parkplatz', 'swimming_pool' => 'Swimmingpool',
        'fitness_center' => 'Fitnessraum', 'restaurant' => 'Restaurant', 'bar' => 'Bar', 'spa' => 'Spa',
        'wheelchair_accessible' => 'Rollstuhlgerecht', 'non_smoking' => 'Nichtraucher', 'room_service' => 'Zimmerservice',
        'daily_housekeeping' => 'Tägliche Zimmerreinigung', 'laundry' => 'Wäscheservice', 'airport_shuttle' => 'Flughafentransfer',
        'front_desk_24h' => 'Rezeption rund um die Uhr',
    ],

    'messages' => ['created' => 'Ausstattung hinzugefügt.', 'updated' => 'Ausstattung gespeichert.', 'facility_saved' => 'Einrichtungen der Unterkunft gespeichert.'],

    'facility_hint' => "Haken Sie an, was die ganze Unterkunft bietet; es erscheint im Profil der Unterkunft. Zimmerausstattung wählen Sie beim jeweiligen Zimmertyp.",
    'errors' => [
        'not_a_facility' => "Nur Ausstattung der ganzen Unterkunft (Pool, Parkplatz, Spa …) kann eine Einrichtung der Unterkunft sein.",
        'duplicate' => 'Eine Ausstattung namens „:name“ gibt es bereits.',
        'global_read_only' => 'Standardausstattung kann nicht geändert werden.',
    ],
];
