<?php

return [
    'title' => 'Taxes et frais',
    'description' => 'Créez et gérez les taxes, frais de service et autres frais. Appliquez-les aux tarifs des chambres, aux suppléments ou aux factures.',
    'add' => 'Ajouter une taxe / des frais',
    'new' => 'Nouvelle taxe / nouveaux frais',
    'settings' => 'Paramètres fiscaux',
    'search' => 'Rechercher une taxe ou des frais…',
    'all_statuses' => 'Tous les statuts',
    'item_plural' => 'éléments',
    'select_rule' => 'Sélectionnez une taxe ou des frais pour les modifier.',
    'no_rules' => 'Aucune taxe ni aucuns frais',
    'no_rules_hint' => 'Ajoutez vos taxes et frais, ou copiez les règles standard de votre pays.',
    'templates_banner' => 'Des règles fiscales standard sont disponibles pour votre pays (pour l’Inde : TPS 0 % jusqu’à 1 000 ₹, 5 % jusqu’à 7 500 ₹, 18 % au-delà, par chambre et par nuit).',
    'copy_templates' => 'Copier les règles standard',

    'tabs' => ['all' => 'Tous', 'tax' => 'Taxes', 'service_charge' => 'Frais de service', 'fee' => 'Frais'],
    'panel_tabs' => ['details' => 'Détails', 'applies' => "S'applique à", 'advanced' => 'Avancé'],

    'columns' => [
        'name' => 'Nom', 'code' => 'Code', 'type' => 'Type', 'rate' => 'Taux / montant', 'apply_to' => "S'applique à",
        'calculation' => 'Calcul', 'status' => 'Statut', 'default' => 'Par défaut', 'actions' => 'Actions',
    ],

    'kinds' => ['tax' => 'Taxe', 'service_charge' => 'Frais de service', 'fee' => 'Frais'],
    'tax_types' => [
        'gst' => 'TPS (GST)', 'vat' => 'TVA', 'sales' => 'Taxe sur les ventes', 'tourism' => 'Taxe touristique', 'city' => 'Taxe de séjour',
        'local' => 'Taxe locale', 'service_charge' => 'Frais de service', 'other' => 'Autre',
    ],
    'apply_to' => ['room_charges' => 'Chambres', 'add_ons' => 'Suppléments', 'fnb' => 'Restauration', 'events' => 'Événements', 'beverage' => 'Boissons', 'liquor' => 'Alcool'],
    'methods' => ['percent' => 'Pourcentage', 'fixed' => 'Montant fixe'],
    'bases' => ['per_room_night' => 'Par chambre et par nuit', 'per_person_night' => 'Par personne et par nuit', 'per_stay' => 'Par séjour', 'per_booking' => 'Par réservation'],
    'basis_short' => ['per_room_night' => 'par chambre et par nuit', 'per_person_night' => 'par personne et par nuit', 'per_stay' => 'par séjour', 'per_booking' => 'par réservation'],
    'component_modes' => ['single' => 'Taxe unique', 'gst_split' => 'TPS : CGST + SGST (IGST entre États)'],

    'fields' => [
        'name' => 'Nom', 'code' => 'Code', 'type' => 'Type', 'rate' => 'Taux / montant', 'basis' => 'Base', 'apply_to' => "S'applique à",
        'method' => 'Méthode de calcul', 'description' => 'Description', 'options' => 'Options', 'status' => 'Statut',
        'default_new' => 'Appliquer par défaut aux nouveaux types de chambres', 'include_displayed' => 'Inclure dans le tarif affiché au client',
        'tax_type' => 'Catégorie fiscale', 'component_mode' => 'Composantes', 'slab' => 'Tranche de tarif (par chambre et par nuit)',
        'slab_min' => 'De', 'slab_max' => 'À', 'inclusive' => 'Les prix incluent déjà cette taxe', 'compound' => 'Composée (appliquée sur prix + taxes précédentes)',
        'priority' => 'Ordre de calcul', 'effective_from' => 'En vigueur du', 'effective_to' => 'En vigueur au',
        'room_types' => 'Types de chambres', 'rate_plans' => 'Plans tarifaires', 'sac' => 'SAC / HSN',
    ],
    'hints' => [
        'slab' => "Laissez vide pour appliquer à tout tarif. Les bornes sont incluses.",
        'applies' => 'Laissez les deux listes vides pour appliquer à tous les types de chambres et plans tarifaires.',
        'priority' => 'Les plus petits nombres sont calculés en premier.',
    ],

    'slab_range' => ':min – :max',
    'slab_up_to' => "jusqu'à :max",
    'slab_above' => 'au-delà de :min',
    'per_room_night_slab' => 'tarif par chambre et par nuit',

    'calculator' => [
        'title' => 'Calculateur de taxes',
        'intro' => "Vérifiez quelles taxes s'appliquent à une nuitée avec les règles actuelles.",
        'tariff' => 'Tarif par chambre et par nuit', 'nights' => 'Nuits', 'persons' => 'Clients', 'guest_state' => "Code de l'État du client",
        'calculate' => 'Calculer', 'taxable' => 'Montant imposable', 'tax_total' => 'Total des taxes', 'total' => 'Total',
        'component' => 'Composante', 'rate' => 'Taux', 'amount' => 'Montant', 'none' => "Aucune taxe ne s'applique.",
    ],

    'delete_confirm' => 'Supprimer :name ? Cette action est irréversible.',

    'messages' => [
        'created' => 'Taxe / frais créés.',
        'updated' => 'Taxe / frais enregistrés.',
        'activated' => 'Taxe / frais activés.',
        'deactivated' => 'Taxe / frais désactivés.',
        'deleted' => 'Taxe / frais supprimés.',
        'templates_copied' => '{0} Aucune règle copiée : elles existent déjà.|{1} :count règle copiée.|[2,*] :count règles copiées.',
    ],

    'errors' => [
        'in_use' => 'Cette règle a été utilisée sur des factures clients et ne peut pas être supprimée. Désactivez-la plutôt.',
        'apply_to_required' => 'Choisissez au moins un type de prestation.',
        'rate_invalid' => 'Saisissez un taux supérieur ou égal à 0.',
        'percent_max' => 'Un pourcentage ne peut pas dépasser 100.',
        'gst_split_tax_only' => 'Seules les taxes peuvent être réparties en CGST et SGST.',
        'slab_order' => 'La borne supérieure doit être au moins égale à la borne inférieure.',
        'dates_order' => 'La date de fin doit être égale ou postérieure à la date de début.',
        'inclusive_percent_only' => 'Seules les taxes en pourcentage peuvent être incluses dans les prix.',
        'code_taken' => 'Le code :code est déjà utilisé.',
    ],
];
