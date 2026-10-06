<?php

return [
    'title' => 'Servizi',
    'description' => 'Servizi standard e quelli personalizzati della tua struttura. Selezionali su ogni tipologia di camera.',
    'add' => 'Aggiungi servizio',
    'edit' => 'Modifica servizio',
    'search' => 'Cerca servizio…',
    'all_categories' => 'Tutte le categorie',
    'item_plural' => 'servizi',
    'global' => 'Standard',
    'custom' => 'Personalizzato',
    'used_by' => '{0} Non usato|{1} :count tipologia|[2,*] :count tipologie',
    'read_only' => 'I servizi standard sono gestiti da E2X e non possono essere modificati.',

    'tabs' => ['all' => 'Tutti', 'global' => 'Standard', 'custom' => 'Personalizzati'],
    'columns' => ['name' => 'Servizio', 'category' => 'Categoria', 'source' => 'Origine', 'used' => 'Usato da', 'status' => 'Stato', 'actions' => 'Azioni', 'facility' => 'Servizio della struttura'],
    'fields' => ['name' => 'Nome', 'category' => 'Categoria', 'icon' => 'Icona', 'status' => 'Stato'],

    'categories' => [
        'room' => 'Camera', 'bathroom' => 'Bagno', 'media' => 'Media e tecnologia', 'kitchen' => 'Cibo e cucina',
        'property' => 'Struttura', 'service' => 'Servizi', 'other' => 'Altro',
    ],

    'items' => [
        'wifi' => 'Wi-Fi gratuito', 'air_conditioning' => 'Aria condizionata', 'heating' => 'Riscaldamento', 'ceiling_fan' => 'Ventilatore a soffitto',
        'desk' => 'Scrivania', 'wardrobe' => 'Armadio', 'seating_area' => 'Zona salotto', 'balcony' => 'Balcone',
        'sea_view' => 'Vista mare', 'mountain_view' => 'Vista montagna', 'safe' => 'Cassaforte', 'telephone' => 'Telefono',
        'iron' => 'Ferro e asse da stiro', 'soundproofing' => 'Insonorizzazione', 'blackout_curtains' => 'Tende oscuranti',
        'baby_cot' => 'Culla su richiesta', 'private_bathroom' => 'Bagno privato', 'bathtub' => 'Vasca da bagno', 'shower' => 'Doccia',
        'hair_dryer' => 'Asciugacapelli', 'toiletries' => 'Prodotti da bagno in omaggio', 'bathrobe' => 'Accappatoio', 'tv' => 'TV',
        'smart_tv' => 'Smart TV', 'satellite_channels' => 'Canali satellitari', 'refrigerator' => 'Frigorifero', 'minibar' => 'Minibar',
        'coffee_maker' => 'Macchina del caffè', 'kettle' => 'Bollitore elettrico', 'microwave' => 'Microonde', 'kitchenette' => 'Angolo cottura',
        'dining_area' => 'Zona pranzo', 'washing_machine' => 'Lavatrice', 'parking' => 'Parcheggio', 'swimming_pool' => 'Piscina',
        'fitness_center' => 'Palestra', 'restaurant' => 'Ristorante', 'bar' => 'Bar', 'spa' => 'Spa',
        'wheelchair_accessible' => 'Accessibile in sedia a rotelle', 'non_smoking' => 'Non fumatori', 'room_service' => 'Servizio in camera',
        'daily_housekeeping' => 'Pulizia giornaliera', 'laundry' => 'Servizio lavanderia', 'airport_shuttle' => 'Navetta aeroportuale',
        'front_desk_24h' => 'Reception 24 ore su 24',
    ],

    'messages' => ['created' => 'Servizio aggiunto.', 'updated' => 'Servizio salvato.', 'facility_saved' => 'Servizi della struttura salvati.'],

    'facility_hint' => "Seleziona i servizi offerti dall'intera struttura; compaiono nel profilo della struttura. I servizi delle camere si scelgono su ogni tipologia.",
    'errors' => [
        'not_a_facility' => "Solo i servizi dell'intera struttura (piscina, parcheggio, spa …) possono essere servizi della struttura.",
        'duplicate' => 'Esiste già un servizio chiamato «:name».',
        'global_read_only' => 'I servizi standard non possono essere modificati.',
    ],
];
