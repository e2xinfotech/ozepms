<?php

return [
    'title' => 'Plans tarifaires',
    'description' => 'Créez et gérez les plans tarifaires : prix, formules repas, conditions d’annulation et restrictions.',
    'add_rate_plan' => 'Ajouter un plan tarifaire',
    'edit_rate_plan' => 'Modifier le plan tarifaire',
    'rate_plan' => 'Plan tarifaire',
    'rate_plan_singular' => 'plan tarifaire',
    'rate_plan_plural' => 'plans tarifaires',
    'details_title' => 'Détails du plan tarifaire',
    'search' => 'Rechercher un plan tarifaire par nom ou code…',
    'all_room_types' => 'Tous les types de chambres',
    'all_meal_plans' => 'Toutes les formules repas',
    'all_statuses' => 'Tous les statuts',
    'copy_suffix' => '(Copie)',
    'default_badge' => 'Par défaut',
    'none_linked' => 'Non lié',
    'select_plan' => 'Sélectionnez un plan tarifaire pour voir ses détails.',
    'no_rate_plans' => 'Aucun plan tarifaire',
    'no_rate_plans_hint' => 'Créez un plan tarifaire, puis liez-le à vos types de chambres.',

    'kpis' => ['all' => 'Tous les plans', 'active' => 'Actifs', 'inactive' => 'Inactifs', 'mapped' => 'Liés aux canaux', 'unmapped' => 'Non liés'],

    'columns' => [
        'name' => 'Nom du plan', 'code' => 'Code', 'meal_plan' => 'Formule repas', 'room_types' => 'Types de chambres', 'policy' => 'Conditions',
        'base_rate' => 'Tarif de base (:currency)', 'min_los' => 'Séjour min.', 'channels' => 'Canaux', 'status' => 'Statut', 'actions' => 'Actions',
    ],

    'panel_tabs' => ['overview' => 'Aperçu', 'rates' => 'Tarifs et restrictions', 'room_types' => 'Types de chambres', 'channels' => 'Canaux'],

    'channels' => [
        'pms' => 'Réception (PMS)', 'booking_engine' => 'Moteur de réservation', 'channels' => 'Canaux OTA',
        'mapped' => ':count produit(s) lié(s) aux canaux', 'not_mapped' => 'Lié à aucun canal pour le moment',
        'mapping_later' => 'Les correspondances de canaux sont gérées dans le Channel Manager.',
    ],

    'quick' => [
        'set_rates' => 'Définir les tarifs', 'copy' => 'Copier', 'deactivate' => 'Désactiver', 'activate' => 'Activer',
        'map_channels' => 'Lier aux canaux', 'view_history' => "Voir l'historique",
    ],

    'fields' => [
        'name' => 'Nom du plan', 'code' => 'Code', 'description' => 'Description (visible par les clients)', 'meal_plan' => 'Formule repas',
        'policy' => "Conditions d'annulation", 'payment_type' => 'Paiement', 'deposit_value' => 'Acompte',
        'min_los' => 'Durée de séjour min.', 'max_los' => 'Durée de séjour max.', 'min_advance' => "Jours d'avance min.",
        'max_advance' => "Jours d'avance max.", 'booking_window' => 'Fenêtre de réservation', 'sell_on' => 'Vendre sur',
        'status' => 'Statut', 'is_default' => "Plan tarifaire par défaut de l'établissement", 'price' => 'Prix par nuit',
        'base_rate' => 'Tarif de base', 'applicable_to' => 'Applicable à', 'pricing' => 'Tarification',
        'parent' => 'Dérivé de', 'adjust_type' => "Type d'ajustement", 'adjust_value' => 'Ajustement',
        'single' => 'Occupation simple', 'extra_adult' => 'Adulte supplémentaire', 'extra_child' => 'Enfant supplémentaire',
        'policy_code' => 'Code des conditions', 'policy_name' => 'Nom des conditions', 'refundable' => 'Remboursable',
        'hours_before' => "Heures avant l'arrivée", 'charge' => 'Frais', 'charge_value' => 'Valeur', 'applies_to' => "S'applique à",
    ],

    'sections' => [
        'basic' => 'Détails du plan tarifaire',
        'basic_desc' => 'Un plan tarifaire est un ensemble de conditions commerciales. Liez-le à des types de chambres ci-dessous pour le vendre.',
        'policy' => 'Formule repas, annulation et paiement',
        'restrictions' => 'Règles de séjour et fenêtre de réservation',
        'distribution' => 'Distribution',
        'room_types' => 'Types de chambres et prix',
        'room_types_desc' => "Chaque type de chambre lié devient un produit. Fixez son prix manuellement ou dérivez-le d'un autre plan tarifaire.",
        'occupancy' => "Prix selon l'occupation",
        'occupancy_desc' => "Par nuit, par rapport au prix de l'occupation de base. Utilisez un montant négatif pour une réduction.",
    ],

    'pricing' => [
        'manual' => 'Prix manuels', 'derived' => "Dérivé d'un autre plan tarifaire",
        'manual_hint' => 'Saisissez un prix par nuit pour chaque type de chambre.',
        'derived_hint' => 'Les prix suivent le plan parent du même type de chambre, p. ex. −10 %.',
        'derive_all' => 'Dériver tous les types de chambres liés de',
        'apply_all' => 'Appliquer à tous',
    ],
    'adjust_types' => ['percent' => 'Pourcentage (%)', 'fixed' => 'Montant fixe', 'fixed_per_person' => 'Montant fixe par personne'],
    'occupancy_types' => ['fixed' => 'Montant', 'percent' => '%'],
    'age_bands' => ['infant' => 'Bébé (:min–:max)', 'child' => 'Enfant (:min–:max)', 'teen' => 'Adolescent (:min–:max)'],

    'payment_types' => [
        'pay_at_property' => "Paiement à l'établissement", 'prepay_full' => 'Prépaiement total',
        'deposit_percent' => 'Acompte (% du séjour)', 'deposit_nights' => 'Acompte (nuits)',
    ],

    'meal_plans' => [
        'RO' => 'Chambre seule', 'BB' => 'Petit-déjeuner', 'HB' => 'Demi-pension', 'FB' => 'Pension complète', 'AI' => 'Tout compris',
        'LO' => 'Déjeuner seul', 'DI' => 'Dîner seul', 'BD' => 'Petit-déjeuner + dîner',
    ],

    'policy' => [
        'refundable' => 'Flexible', 'non_refundable' => 'Non remboursable', 'new' => 'Nouvelles conditions', 'edit' => 'Modifier les conditions',
        'rules' => 'Frais', 'add_rule' => 'Ajouter des frais', 'cancellation' => 'Annulation', 'no_show' => 'Non-présentation',
        'charge_types' => [
            'none' => 'Gratuit', 'first_night' => 'Première nuit', 'nights' => 'Nombre de nuits', 'percent' => 'Pourcentage du séjour',
            'fixed' => 'Montant fixe', 'full' => 'Séjour complet',
        ],
        'rule_text' => ":charge en cas d'annulation moins de :hours h avant l'arrivée",
        'no_show_text' => 'Non-présentation : :charge',
    ],

    'one_night' => '1 nuit',
    'n_nights' => ':count nuits',
    'nights_suffix' => 'nuits',
    'current_price' => 'Prix actuel : :price',
    'daily_rates_hint' => 'Les prix et restrictions journaliers se définissent dans le Calendrier.',
    'no_room_types' => "Aucun type de chambre. Créez d'abord un type de chambre.",
    'occupancy' => [
        'adult' => 'Adultes', 'child' => 'Enfant', 'infant' => 'Bébé', 'guest' => 'Type de client', 'count' => 'Nombre de clients',
        'count_hint' => 'Adultes : nombre total d\'adultes dans la chambre (1 = occupation simple). Enfants : le n-ième enfant.',
        'age_band' => "Tranche d'âge", 'add' => 'Ajouter une règle', 'none' => 'Aucune règle : le prix est le même quel que soit le nombre de clients.',
    ],
    'days_range' => ':from – :to jours',
    'any' => 'Toutes',

    'defaults' => [
        'bar_name' => 'Tarif standard – Chambre seule',
        'bar_text' => 'Meilleur tarif disponible, sans repas.',
        'flexible_name' => "Flexible – annulation gratuite jusqu'à 24 heures avant l'arrivée",
        'flexible_text' => "Annulation gratuite jusqu'à 24 heures avant l'arrivée. Les annulations tardives et les non-présentations sont facturées la première nuit.",
        'non_refundable_name' => 'Non remboursable',
        'non_refundable_text' => "Le séjour complet est facturé à la réservation et n'est pas remboursé en cas d'annulation ou de non-présentation.",
    ],

    'messages' => [
        'created' => 'Plan tarifaire créé.',
        'updated' => 'Plan tarifaire enregistré.',
        'activated' => 'Plan tarifaire activé.',
        'deactivated' => 'Plan tarifaire désactivé.',
        'copied' => 'Copie créée sous le code :code (inactive). Vérifiez-la, puis activez-la.',
        'policy_saved' => "Conditions d'annulation enregistrées.",
    ],

    'errors' => [
        'adjust_percent_range' => 'Un ajustement en pourcentage doit être supérieur à −100.',
        'adjust_required' => "Saisissez le type et la valeur de l'ajustement.",
        'age_band_unknown' => "Tranche d'âge inconnue.",
        'has_active_children' => ":count produit(s) actif(s) sont dérivés de celui-ci. Modifiez-les d'abord.",
        'non_refundable_free' => "Des conditions non remboursables ne peuvent pas prévoir d'annulation gratuite.",
        'occupancy_count' => 'Le nombre de clients doit être compris entre 1 et :max.',
        'occupancy_duplicate' => "Cette règle d'occupation est saisie deux fois.",
        'parent_cycle' => 'Le prix dépendrait de lui-même.',
        'parent_inactive' => 'Le lien du plan parent est inactif.',
        'parent_missing' => 'Choisissez le plan tarifaire dont ce prix est dérivé.',
        'parent_not_linked' => ":plan n'est pas lié à :room_type. Liez-le d'abord ou utilisez un prix manuel.",
        'parent_other_property' => 'Le plan parent appartient à un autre établissement.',
        'parent_self' => "Un plan tarifaire ne peut pas être dérivé de lui-même.",
        'parent_too_deep' => 'Les prix dérivés peuvent être enchaînés sur :max niveaux au plus.',
        'price_required' => 'Saisissez un prix supérieur ou égal à 0.',
        'product_inactive' => 'Ce lien de plan tarifaire est inactif.',
        'rule_duplicate_window' => 'Deux frais utilisent la même plage horaire.',
        'rule_percent_max' => 'Un pourcentage ne peut pas dépasser 100.',
        'rule_value_required' => 'Saisissez une valeur pour ces frais.',
        'max_los_below_min' => 'La durée de séjour max. ne peut pas être inférieure au minimum.',
        'window_order' => 'Le dernier jour de réservation doit être après le premier.',
        'deposit_value' => 'Saisissez un acompte supérieur à 0 (au plus 100 pour un pourcentage).',
        'default_cannot_deactivate' => "Le plan tarifaire par défaut ne peut pas être désactivé. Définissez d'abord un autre plan par défaut.",
        'default_inactive' => 'Un plan tarifaire inactif ne peut pas être le plan par défaut.',
        'meal_plan_required' => 'Choisissez une formule repas.',
        'policy_required' => "Choisissez des conditions d'annulation.",
    ],
    'setup' => ['title' => 'Configuration, étape 2 sur 3 :', 'text' => 'créez le plan tarifaire avec lequel vos chambres sont vendues. Ensuite, vous ajoutez les types de chambres et leurs chambres PMS.'],
];
