<?php

return [
    'modules' => [
        'platform' => 'Plateforme', 'property' => 'Établissement', 'rooms' => 'Chambres', 'rate_plans' => 'Plans tarifaires', 'calendar' => 'Calendrier',
        'reservations' => 'Réservations', 'front_desk' => 'Réception', 'guests' => 'Clients', 'billing' => 'Facturation',
        'offers' => 'Offres', 'taxes' => 'Taxes', 'channels' => 'Canaux', 'reports' => 'Rapports', 'users' => 'Utilisateurs',
    ],
    'keys' => [
        'platform.dashboard' => 'Voir le tableau de bord de la plateforme',
        'platform.properties.manage' => 'Gérer tous les établissements',
        'platform.users.manage' => 'Gérer les utilisateurs de la plateforme',
        'platform.plans.manage' => 'Gérer les forfaits d\'abonnement',
        'platform.subscriptions.manage' => 'Gérer les abonnements',
        'platform.audit.view' => 'Voir le journal d\'audit',
        'platform.system.view' => 'Voir l\'état du système',
        'property.view' => 'Voir l\'établissement',
        'property.update' => 'Modifier les paramètres de l\'établissement',
        'rooms.view' => 'Voir les chambres', 'rooms.create' => 'Créer des chambres', 'rooms.update' => 'Modifier les chambres',
        'rate_plans.view' => 'Voir les plans tarifaires', 'rate_plans.create' => 'Créer des plans tarifaires', 'rate_plans.update' => 'Modifier les plans tarifaires',
        'calendar.view' => 'Voir le calendrier', 'calendar.update' => 'Modifier tarifs et disponibilités',
        'reservations.view' => 'Voir les réservations', 'reservations.create' => 'Créer des réservations',
        'reservations.update' => 'Modifier les réservations', 'reservations.cancel' => 'Annuler les réservations',
        'checkin.perform' => 'Enregistrer les arrivées', 'checkout.perform' => 'Enregistrer les départs', 'checkout.override_balance' => 'Enregistrer un départ avec un solde ouvert', 'housekeeping.update' => 'Mettre à jour le ménage',
        'guests.view' => 'Voir les clients', 'guests.update' => 'Modifier les clients',
        'folio.view' => 'Voir les folios', 'folio.post' => 'Imputer des frais', 'payments.manage' => 'Enregistrer paiements et remboursements',
        'invoices.manage' => 'Émettre des factures', 'services.manage' => 'Gérer les services et extras', 'billing.override' => 'Passer outre les contrôles de facturation (annuler des nuitées, solde ouvert)',
        'offers.manage' => 'Gérer les offres', 'taxes.manage' => 'Gérer taxes et frais', 'channels.manage' => 'Gérer les canaux',
        'reports.view' => 'Voir les rapports', 'users.manage' => 'Gérer utilisateurs et rôles',
    ],
];
