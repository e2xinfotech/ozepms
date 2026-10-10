<?php

return [
    'greeting' => 'Bonjour :name,',
    'subject' => [
        'confirmed' => 'Réservation confirmée :ref — :hotel',
        'received' => 'Réservation reçue :ref — :hotel',
        'modified' => 'Votre réservation :ref a été modifiée — :hotel',
        'cancelled' => 'Réservation annulée :ref — :hotel',
        'thanks' => 'Merci de votre séjour à :hotel',
    ],
    'intro' => [
        'confirmed' => 'Merci d’avoir réservé à :hotel. Votre réservation est confirmée.',
        'received' => 'Merci d’avoir réservé à :hotel. Nous avons bien reçu votre réservation et la confirmerons sous peu.',
        'modified' => 'Votre réservation à :hotel a été modifiée. Voici les détails actuels.',
        'cancelled' => 'Votre réservation à :hotel a été annulée.',
        'thanks' => 'Merci d’avoir séjourné à :hotel. Nous espérons que votre séjour vous a plu et serons heureux de vous accueillir à nouveau.',
    ],
    'labels' => [
        'ref' => 'Référence de réservation',
        'check_in' => 'Arrivée',
        'check_out' => 'Départ',
        'nights' => 'Nuits',
        'rooms' => 'Chambres',
        'guests' => 'Voyageurs',
        'total' => 'Total',
        'fee' => 'Frais d’annulation',
    ],
    'guests_value' => 'Adultes : :adults · Enfants : :children',
    'view' => 'Voir votre réservation',
    'contact' => 'Une question ? Contactez :hotel : :contact',
    'salutation' => 'Cordialement, :hotel',
    'footer' => 'Cet e-mail a été envoyé par :hotel au sujet de votre réservation. Ne partagez pas le lien de réservation avec d’autres personnes.',
    'test' => [
        'subject' => 'E-mail de test de :app',
        'body' => 'Ceci est un e-mail de test. Si vous pouvez le lire, vos paramètres e-mail fonctionnent.',
        'sent_with' => 'Envoyé via :host.',
    ],
];
