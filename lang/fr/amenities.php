<?php

return [
    'title' => 'Équipements',
    'description' => 'Équipements standard et équipements personnalisés de votre établissement. Sélectionnez-les sur chaque type de chambre.',
    'add' => 'Ajouter un équipement',
    'edit' => "Modifier l'équipement",
    'search' => 'Rechercher un équipement…',
    'all_categories' => 'Toutes les catégories',
    'item_plural' => 'équipements',
    'global' => 'Standard',
    'custom' => 'Personnalisé',
    'used_by' => '{0} Non utilisé|{1} :count type de chambre|[2,*] :count types de chambres',
    'read_only' => 'Les équipements standard sont gérés par E2X et ne peuvent pas être modifiés.',

    'tabs' => ['all' => 'Tous', 'global' => 'Standard', 'custom' => 'Personnalisés'],
    'columns' => ['name' => 'Équipement', 'category' => 'Catégorie', 'source' => 'Origine', 'used' => 'Utilisé par', 'status' => 'Statut', 'actions' => 'Actions', 'facility' => "Service de l'établissement"],
    'fields' => ['name' => 'Nom', 'category' => 'Catégorie', 'icon' => 'Icône', 'status' => 'Statut'],

    'categories' => [
        'room' => 'Chambre', 'bathroom' => 'Salle de bain', 'media' => 'Médias et technologie', 'kitchen' => 'Restauration et cuisine',
        'property' => 'Établissement', 'service' => 'Services', 'other' => 'Autre',
    ],

    'items' => [
        'wifi' => 'Wi-Fi gratuit', 'air_conditioning' => 'Climatisation', 'heating' => 'Chauffage', 'ceiling_fan' => 'Ventilateur de plafond',
        'desk' => 'Bureau', 'wardrobe' => 'Armoire', 'seating_area' => 'Coin salon', 'balcony' => 'Balcon',
        'sea_view' => 'Vue mer', 'mountain_view' => 'Vue montagne', 'safe' => 'Coffre-fort', 'telephone' => 'Téléphone',
        'iron' => 'Fer et planche à repasser', 'soundproofing' => 'Insonorisation', 'blackout_curtains' => 'Rideaux occultants',
        'baby_cot' => 'Lit bébé sur demande', 'private_bathroom' => 'Salle de bain privée', 'bathtub' => 'Baignoire', 'shower' => 'Douche',
        'hair_dryer' => 'Sèche-cheveux', 'toiletries' => 'Articles de toilette offerts', 'bathrobe' => 'Peignoir', 'tv' => 'Télévision',
        'smart_tv' => 'Smart TV', 'satellite_channels' => 'Chaînes satellite', 'refrigerator' => 'Réfrigérateur', 'minibar' => 'Minibar',
        'coffee_maker' => 'Machine à café', 'kettle' => 'Bouilloire', 'microwave' => 'Micro-ondes', 'kitchenette' => 'Kitchenette',
        'dining_area' => 'Coin repas', 'washing_machine' => 'Lave-linge', 'parking' => 'Parking', 'swimming_pool' => 'Piscine',
        'fitness_center' => 'Salle de sport', 'restaurant' => 'Restaurant', 'bar' => 'Bar', 'spa' => 'Spa',
        'wheelchair_accessible' => 'Accessible en fauteuil roulant', 'non_smoking' => 'Non-fumeur', 'room_service' => 'Service en chambre',
        'daily_housekeeping' => 'Ménage quotidien', 'laundry' => 'Blanchisserie', 'airport_shuttle' => 'Navette aéroport',
        'front_desk_24h' => 'Réception 24h/24',
    ],

    'messages' => ['created' => 'Équipement ajouté.', 'updated' => 'Équipement enregistré.', 'facility_saved' => "Services de l'établissement enregistrés."],

    'facility_hint' => "Cochez les services offerts par tout l'établissement ; ils apparaissent sur sa fiche. Les équipements des chambres se choisissent sur chaque type de chambre.",
    'errors' => [
        'not_a_facility' => "Seuls les équipements de tout l'établissement (piscine, parking, spa …) peuvent être des services de l'établissement.",
        'duplicate' => 'Un équipement nommé « :name » existe déjà.',
        'global_read_only' => 'Les équipements standard ne peuvent pas être modifiés.',
    ],
    'add_missing' => 'Ajouter un équipement manquant',
    'add_hint' => 'Enregistré dans la liste centrale des équipements et disponible pour tous les types de chambre et chambres.',
    'room_only' => 'Cette chambre uniquement',
    'from_room_type' => 'Inclus avec le type de chambre',
];
